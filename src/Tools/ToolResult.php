<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Tools;

use InvalidArgumentException;
use JsonSerializable;

/**
 * A tool result that is more than one value: text written for the model beside
 * structured content for the tool's view (an MCP App), or text and images the
 * model should see (a rendered map), or both.
 *
 * - `withView($text, $structured)`: the CLI receives the text (`tool_resolve`); the
 *   server keeps the full result and hands it to the host on the stream's
 *   `tool_result` as `ui.result` (see PROTOCOL.md).
 * - `withContent([ToolResult::textPart(...), ToolResult::imagePart(...)])`: a bridge
 *   that announced `hello.tool_result_content` hands the parts to the CLI as MCP
 *   content blocks, so the model sees the images; any other bridge gets the text,
 *   with each image described in its place (`[image: image/webp, 210 KB]`).
 *
 * Image bytes go to the bridge and nowhere else: the view's copy, the log and
 * everything recorded carry the description instead.
 *
 * Returned from a tool WITHOUT a view, a view-only result is simply its text.
 */
final class ToolResult implements JsonSerializable
{
    /** The image types the CLIs that read images accept (Anthropic's list). */
    public const IMAGE_MIME_TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];

    /** At most this many parts in one result (the bridge refuses more). */
    public const MAX_PARTS = 32;

    /**
     * @param  array<string, mixed>|null  $structured
     * @param  list<array{type: 'text', text: string}|array{type: 'image', data: string, mimeType: string}>|null  $content
     */
    private function __construct(
        public readonly string $text,
        public readonly ?array $structured = null,
        private readonly ?array $content = null,
    ) {}

    /**
     * @param  string  $text  What the model reads.
     * @param  array<string, mixed>  $structured  What the view reads besides (`structuredContent`).
     */
    public static function withView(string $text, array $structured): self
    {
        return new self($text, $structured);
    }

    /**
     * A result of text and image parts, in the order the model should read them.
     *
     * @param  list<array<string, mixed>>  $parts  Made with textPart() and imagePart().
     * @param  array<string, mixed>|null  $structured  What a view reads besides, if the tool has one.
     *
     * @throws InvalidArgumentException For no parts, too many, or a part that is not one of the two.
     */
    public static function withContent(array $parts, ?array $structured = null): self
    {
        if ($parts === [] || ! array_is_list($parts)) {
            throw new InvalidArgumentException('A ToolResult needs a list of at least one part.');
        }
        if (count($parts) > self::MAX_PARTS) {
            throw new InvalidArgumentException('A ToolResult carries at most '.self::MAX_PARTS.' parts.');
        }

        $content = [];
        foreach ($parts as $i => $part) {
            $content[] = match ($part['type'] ?? null) {
                'text' => self::textPart(is_string($part['text'] ?? null) ? $part['text'] : throw new InvalidArgumentException("Part {$i}: text must be a string.")),
                'image' => self::imagePart((string) ($part['mimeType'] ?? ''), (string) ($part['data'] ?? '')),
                default => throw new InvalidArgumentException("Part {$i}: use ToolResult::textPart() or ToolResult::imagePart()."),
            };
        }

        $text = implode("\n", array_map(
            static fn (array $part): string => $part['type'] === 'text' ? $part['text'] : self::describeImage($part),
            $content,
        ));

        return new self($text, $structured, $content);
    }

    /**
     * @return array{type: 'text', text: string}
     */
    public static function textPart(string $text): array
    {
        return ['type' => 'text', 'text' => $text];
    }

    /**
     * An image part. `$base64` is the image already base64-encoded, e.g.
     * `base64_encode($webpBytes)`.
     *
     * @return array{type: 'image', data: string, mimeType: string}
     *
     * @throws InvalidArgumentException For a type the CLIs do not read, or data that is not base64.
     */
    public static function imagePart(string $mimeType, string $base64): array
    {
        if (! in_array($mimeType, self::IMAGE_MIME_TYPES, true)) {
            throw new InvalidArgumentException('An image part is one of '.implode(', ', self::IMAGE_MIME_TYPES).", not '{$mimeType}'.");
        }
        if ($base64 === '' || strlen($base64) % 4 !== 0 || base64_decode($base64, true) === false) {
            throw new InvalidArgumentException('An image part carries its bytes base64-encoded.');
        }

        return ['type' => 'image', 'data' => $base64, 'mimeType' => $mimeType];
    }

    /**
     * The parts for `tool_resolve.content`, or null for a result without any.
     *
     * @return list<array{type: 'text', text: string}|array{type: 'image', data: string, mimeType: string}>|null
     */
    public function content(): ?array
    {
        return $this->content;
    }

    /** Whether any part is an image. */
    public function hasImages(): bool
    {
        foreach ($this->content ?? [] as $part) {
            if ($part['type'] === 'image') {
                return true;
            }
        }

        return false;
    }

    /** Decoded size of the images, in bytes. */
    public function imageBytes(): int
    {
        $bytes = 0;
        foreach ($this->content ?? [] as $part) {
            if ($part['type'] === 'image') {
                $bytes += intdiv(strlen($part['data']) * 3, 4);
            }
        }

        return $bytes;
    }

    /**
     * The MCP `CallToolResult` a view receives.
     *
     * Images are described, not carried: a view's result is kept in the stream store
     * and in the recorded turn, which never hold image bytes.
     *
     * @return array{content: list<array{type: string, text: string}>, structuredContent?: array<string, mixed>}
     */
    public function toCallToolResult(): array
    {
        $content = $this->content === null
            ? [self::textPart($this->text)]
            : array_map(
                static fn (array $part): array => $part['type'] === 'text' ? $part : self::textPart(self::describeImage($part)),
                $this->content,
            );

        $result = ['content' => $content];

        if ($this->structured !== null) {
            $result['structuredContent'] = $this->structured;
        }

        return $result;
    }

    /**
     * `[image: image/webp, 210 KB]`: what stands for an image wherever its bytes do not go.
     *
     * @param  array{data: string, mimeType: string}  $part
     */
    public static function describeImage(array $part): string
    {
        return sprintf('[image: %s, %d KB]', $part['mimeType'], (int) round(strlen($part['data']) * 3 / 4 / 1024));
    }

    /** Encoded as the text alone: images described, never their bytes. */
    public function jsonSerialize(): string
    {
        return $this->text;
    }
}
