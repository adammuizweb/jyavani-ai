<?php
declare(strict_types=1);

final class JaiProviderException extends RuntimeException
{
    public function __construct(public readonly int $providerStatus, public readonly string $reason)
    {
        parent::__construct('AI provider failed with HTTP ' . $providerStatus . ' (' . $reason . ').');
    }
}

function jai_provider_failure_reason(int $status, array $payload): string
{
    $error = is_array($payload['error'] ?? null) ? $payload['error'] : [];
    $code = strtolower((string)($error['code'] ?? ''));
    $providerStatus = strtoupper((string)($error['status'] ?? ''));
    if (in_array($status, [401, 403], true)) return 'credentials';
    if ($status === 429) {
        if (in_array($code, ['insufficient_quota', 'credit_balance_exhausted'], true) || $providerStatus === 'RESOURCE_EXHAUSTED') return 'quota';
        return 'rate_limit';
    }
    if ($status === 503 || $providerStatus === 'UNAVAILABLE') return 'unavailable';
    return 'provider_error';
}

function jai_provider_public_error(Throwable $error): array
{
    if (!$error instanceof JaiProviderException) return [jai_t('The AI request failed.'), 502];
    return match ($error->reason) {
        'credentials' => [jai_t('The AI provider rejected the API key.'), 502],
        'quota' => [jai_t('The AI provider quota or credit is unavailable.'), 502],
        'rate_limit' => [jai_t('The AI provider rate limit was reached. Try again shortly.'), 503],
        'unavailable' => [jai_t('The AI provider is temporarily unavailable. Try again.'), 503],
        default => [jai_t('The AI request failed.'), 502],
    };
}

function jai_operation_instruction(string $operation, string $custom): string
{
    $base = match ($operation) {
        'improve' => 'Improve clarity, grammar, structure, and readability while preserving meaning and factual claims.',
        'rewrite' => 'Rewrite the content substantially while preserving its meaning and factual claims.',
        'shorten' => 'Make the content more concise without removing essential information.',
        'expand' => 'Expand the content with useful explanation while avoiding invented facts.',
        'translate' => 'Translate the content according to the requested target language and preserve its structure.',
        'custom' => 'Follow the user instructions exactly where they do not conflict with the safety rules.',
        default => throw new InvalidArgumentException('Unsupported AI operation.'),
    };
    return $custom === '' ? $base : $base . "\nUser instructions: " . $custom;
}

function jai_provider_messages(string $operation, string $custom, string $source, string $editorMode, string $target): array
{
    $system = 'You are a writing assistant embedded in Jyavani CMS. Return only the replacement content, without markdown fences or commentary. '
        . 'Preserve factual claims unless the user explicitly asks to change them. Never emit script, style, iframe, object, embed, form, event-handler attributes, or executable URLs. '
        . ($editorMode === 'codemirror'
            ? 'The source is HTML. Return valid HTML suitable for direct source editing.'
            : 'Return conservative HTML suitable for a Quill rich-text editor, using paragraphs, headings, lists, links, emphasis, images, and plain-text table cells only.');
    $user = "Task target: {$target}\n" . jai_operation_instruction($operation, $custom) . "\n\nSOURCE CONTENT:\n" . $source;
    return [
        ['role' => 'system', 'content' => $system],
        ['role' => 'user', 'content' => $user],
    ];
}

function jai_provider_extract_content(array $payload): string
{
    $content = $payload['choices'][0]['message']['content'] ?? null;
    if (is_string($content)) {
        $content = trim($content);
        if ($content === '') throw new RuntimeException('Provider response did not contain generated content.');
        return $content;
    }
    if (!is_array($content)) throw new RuntimeException('Provider response did not contain generated content.');
    $parts = [];
    foreach ($content as $part) {
        if (is_array($part) && ($part['type'] ?? '') === 'text' && is_string($part['text'] ?? null)) {
            $parts[] = $part['text'];
        }
    }
    $result = trim(implode('', $parts));
    if ($result === '') throw new RuntimeException('Provider response did not contain generated content.');
    return $result;
}

function jai_sanitize_generated_content(string $content): string
{
    if (!function_exists('cms_sanitize_restricted_html')) throw new RuntimeException('Core HTML sanitizer is unavailable.');
    $content = cms_sanitize_restricted_html($content);
    if (function_exists('normalize_links_in_html') && class_exists('DOMDocument')) {
        $content = normalize_links_in_html($content);
    }
    if (trim($content) === '') throw new RuntimeException('Generated content was removed by the safety policy.');
    return $content;
}

function jai_provider_generate(array $messages): array
{
    if (!jai_provider_is_ready()) throw new RuntimeException('AI provider is not configured.');
    $providerUrl = jai_provider_url();
    $providerParts = parse_url($providerUrl);
    $providerHost = trim((string)($providerParts['host'] ?? ''), '[]');
    $providerPort = (int)($providerParts['port'] ?? 443);
    $providerAddresses = jai_provider_public_addresses($providerUrl);
    $request = json_encode([
        'model' => jai_provider_model(),
        'messages' => $messages,
        'temperature' => 0.4,
        'max_tokens' => jai_max_output_tokens(),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

    $responseLimit = 2 * 1024 * 1024;
    $response = '';
    $tooLarge = false;
    $ok = false;
    $status = 0;
    $curlError = '';
    for ($attempt = 0; $attempt < 2; $attempt++) {
        $response = '';
        $tooLarge = false;
        $ch = curl_init($providerUrl);
        if ($ch === false) throw new RuntimeException('Unable to initialize the AI provider request.');
        $options = [
            CURLOPT_POST => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => min(10, jai_provider_timeout()),
            CURLOPT_TIMEOUT => jai_provider_timeout(),
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/json',
                'Authorization: Bearer ' . jai_provider_api_key(),
            ],
            CURLOPT_POSTFIELDS => $request,
            CURLOPT_HEADER => false,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$response, &$tooLarge, $responseLimit): int {
                if (strlen($response) + strlen($chunk) > $responseLimit) {
                    $tooLarge = true;
                    return 0;
                }
                $response .= $chunk;
                return strlen($chunk);
            },
        ];
        if (filter_var($providerHost, FILTER_VALIDATE_IP) === false) {
            $pinnedAddress = $providerAddresses[$attempt % count($providerAddresses)];
            $resolveAddress = str_contains($pinnedAddress, ':') ? '[' . $pinnedAddress . ']' : $pinnedAddress;
            $options[CURLOPT_RESOLVE] = [$providerHost . ':' . $providerPort . ':' . $resolveAddress];
        }
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
        if (defined('CURLOPT_REDIR_PROTOCOLS') && defined('CURLPROTO_HTTPS')) $options[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTPS;
        curl_setopt_array($ch, $options);
        $ok = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        if ($status !== 503 || $attempt === 1) break;
        usleep(500000);
    }

    if ($tooLarge) throw new RuntimeException('AI provider response exceeded the size limit.');
    if ($ok === false) throw new RuntimeException('AI provider request failed' . ($curlError !== '' ? ': transport error' : '.'));
    $decoded = json_decode($response, true);
    if (!is_array($decoded)) throw new RuntimeException('AI provider returned invalid JSON.');
    if ($status < 200 || $status >= 300) throw new JaiProviderException($status, jai_provider_failure_reason($status, $decoded));

    $content = jai_sanitize_generated_content(jai_provider_extract_content($decoded));
    if (strlen($content) > jai_max_input_bytes()) throw new RuntimeException('Generated content exceeded the configured size limit.');
    $usage = is_array($decoded['usage'] ?? null) ? $decoded['usage'] : [];
    return [
        'content' => $content,
        'input_tokens' => max(0, (int)($usage['prompt_tokens'] ?? 0)),
        'output_tokens' => max(0, (int)($usage['completion_tokens'] ?? 0)),
    ];
}
