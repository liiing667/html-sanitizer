<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\HtmlSanitizer\Sanitization;

use Symfony\Component\HtmlSanitizer\HtmlSanitizerAction;

/**
 * Maps one node of the published sanitized output back to its input path,
 * element policy and concrete modifications.
 */
final class SanitizationNodeEvidence
{
    /**
     * @param array<string, string|null>         $attributes
     * @param list<SanitizationModification>     $modifications
     */
    public function __construct(
        private readonly int $outputIndex,
        private readonly string $outputPath,
        private readonly string $inputPath,
        private readonly string $type,
        private readonly ?string $name,
        private readonly string $policy,
        private readonly string $action,
        private readonly array $attributes = [],
        private readonly array $modifications = [],
    ) {
    }

    public function getOutputIndex(): int
    {
        return $this->outputIndex;
    }

    public function getOutputPath(): string
    {
        return $this->outputPath;
    }

    public function getInputPath(): string
    {
        return $this->inputPath;
    }

    public function getNodePath(): string
    {
        return $this->inputPath;
    }

    public function getInputNodePath(): string
    {
        return $this->inputPath;
    }

    public function getInputXPath(): string
    {
        return '/'.str_replace(' > ', '/', $this->inputPath);
    }

    public function getOutputXPath(): string
    {
        return '/'.str_replace(' > ', '/', $this->outputPath);
    }

    public function getPath(): string
    {
        return $this->inputPath;
    }

    /**
     * One of "element" or "text".
     */
    public function getType(): string
    {
        return $this->type;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function getTagName(): ?string
    {
        return 'element' === $this->type ? $this->name : null;
    }

    public function getElement(): ?string
    {
        return $this->getTagName();
    }

    public function getNodeName(): string
    {
        return $this->name ?? '#text';
    }

    /**
     * The element policy that produced this output node: "allow", "block" or "text".
     */
    public function getPolicy(): string
    {
        return $this->policy;
    }

    public function getStrategy(): string
    {
        return $this->policy;
    }

    public function getPolicyAction(): ?HtmlSanitizerAction
    {
        return HtmlSanitizerAction::tryFrom($this->policy);
    }

    /**
     * The action applied to this output node: "keep", "block" or "encode".
     */
    public function getAction(): string
    {
        return $this->action;
    }

    /**
     * @return array<string, string|null>
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    public function getAttribute(string $name): ?string
    {
        return $this->attributes[$name] ?? null;
    }

    /**
     * @return list<SanitizationModification>
     */
    public function getModifications(): array
    {
        return $this->modifications;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'output_index' => $this->outputIndex,
            'output_path' => $this->outputPath,
            'input_path' => $this->inputPath,
            'type' => $this->type,
            'name' => $this->name,
            'policy' => $this->policy,
            'action' => $this->action,
            'attributes' => $this->attributes,
            'modifications' => array_map(
                static fn (SanitizationModification $modification): array => $modification->toArray(),
                $this->modifications,
            ),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $modifications = [];
        foreach ($data['modifications'] ?? [] as $modification) {
            if (\is_array($modification)) {
                $modifications[] = SanitizationModification::fromArray($modification);
            }
        }

        return new self(
            (int) ($data['output_index'] ?? 0),
            (string) ($data['output_path'] ?? ''),
            (string) ($data['input_path'] ?? 'document'),
            (string) ($data['type'] ?? 'element'),
            isset($data['name']) ? (string) $data['name'] : null,
            (string) ($data['policy'] ?? 'unknown'),
            (string) ($data['action'] ?? 'unknown'),
            \is_array($data['attributes'] ?? null) ? $data['attributes'] : [],
            $modifications,
        );
    }
}
