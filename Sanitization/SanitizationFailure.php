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

/**
 * Describes the exact point at which a sanitization transaction failed.
 *
 * A failure never carries sanitized output: consumers must either use the
 * complete output of a successful report or expose this failure. The input
 * path identifies the source node, while the stage identifies whether the
 * failure happened while parsing, visiting, sanitizing an attribute,
 * rendering or committing the evidence.
 */
final class SanitizationFailure
{
    public const STAGE_PARSE = 'parse';
    public const STAGE_FREEZE = 'freeze-input';
    public const STAGE_VISITOR = 'visitor';
    public const STAGE_VISIT = 'visit';
    public const STAGE_ATTRIBUTE = 'attribute-sanitization';
    public const STAGE_RENDER = 'render';
    public const STAGE_COMMIT = 'commit';
    public const STAGE_CANCELLED = 'cancelled';

    public function __construct(
        private readonly string $stage,
        private readonly string $message,
        private readonly ?string $inputPath = null,
        private readonly ?string $outputPath = null,
        private readonly ?string $element = null,
        private readonly ?string $attribute = null,
        private readonly ?string $policy = null,
        private readonly ?string $sanitizer = null,
        private readonly ?string $exceptionClass = null,
        private readonly ?int $code = null,
    ) {
    }

    public function getStage(): string
    {
        return $this->stage;
    }

    public function getReason(): string
    {
        return $this->message;
    }

    public function getMessage(): string
    {
        return $this->getFailureMessage();
    }

    public function getInputPath(): ?string
    {
        return $this->inputPath;
    }

    /**
     * Alias used by reports that expose the failing source node as a path.
     */
    public function getNodePath(): ?string
    {
        return $this->inputPath;
    }

    public function getOutputPath(): ?string
    {
        return $this->outputPath;
    }

    public function getElement(): ?string
    {
        return $this->element;
    }

    public function getElementName(): ?string
    {
        return $this->element;
    }

    public function getAttribute(): ?string
    {
        return $this->attribute;
    }

    public function getAttributeName(): ?string
    {
        return $this->attribute;
    }

    public function getPolicy(): ?string
    {
        return $this->policy;
    }

    public function getSanitizer(): ?string
    {
        return $this->sanitizer;
    }

    public function getExceptionClass(): ?string
    {
        return $this->exceptionClass;
    }

    public function getCode(): ?int
    {
        return $this->code;
    }

    public function getLocation(): string
    {
        return $this->inputPath ?? 'document';
    }

    public function getFailureLocation(): string
    {
        return $this->getLocation();
    }

    public function getFailureMessage(): string
    {
        $location = $this->getLocation();
        $context = [];

        if (null !== $this->element) {
            $context[] = \sprintf('element <%s>', $this->element);
        }
        if (null !== $this->attribute) {
            $context[] = \sprintf('attribute "%s"', $this->attribute);
        }
        if (null !== $this->policy) {
            $context[] = \sprintf('policy "%s"', $this->policy);
        }
        if (null !== $this->sanitizer) {
            $context[] = \sprintf('sanitizer "%s"', $this->sanitizer);
        }

        return \sprintf(
            'Sanitization failed during stage "%s" at node "%s"%s: %s',
            $this->stage,
            $location,
            $context ? ' ('.implode(', ', $context).')' : '',
            $this->message,
        );
    }

    public function __toString(): string
    {
        return $this->getFailureMessage();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'stage' => $this->stage,
            'message' => $this->message,
            'display_message' => $this->getFailureMessage(),
            'location' => $this->getLocation(),
            'input_path' => $this->inputPath,
            'output_path' => $this->outputPath,
            'element' => $this->element,
            'attribute' => $this->attribute,
            'policy' => $this->policy,
            'sanitizer' => $this->sanitizer,
            'exception_class' => $this->exceptionClass,
            'code' => $this->code,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['stage'] ?? 'unknown'),
            (string) ($data['message'] ?? 'Unknown sanitization failure.'),
            isset($data['input_path']) ? (string) $data['input_path'] : null,
            isset($data['output_path']) ? (string) $data['output_path'] : null,
            isset($data['element']) ? (string) $data['element'] : null,
            isset($data['attribute']) ? (string) $data['attribute'] : null,
            isset($data['policy']) ? (string) $data['policy'] : null,
            isset($data['sanitizer']) ? (string) $data['sanitizer'] : null,
            isset($data['exception_class']) ? (string) $data['exception_class'] : null,
            isset($data['code']) ? (int) $data['code'] : null,
        );
    }
}
