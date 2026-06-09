<?php

declare(strict_types=1);

namespace Framework\Http\Request;

use Framework\Validation\Rule\RuleRegistry;
use Framework\Validation\Validator;

/**
 * Bind a {@see Request} (or arbitrary data) into a validated DTO.
 *
 * The binder owns all DTO-binding concerns: which source is consulted
 * (JSON priority, form fallback, or caller-supplied data), the
 * validator-driven hydration, and the error mapping. Pulling these
 * out of {@see Request} lets the DTO stay pure and lets callers that
 * want a non-default validator (e.g. one wired with a custom
 * {@see RuleRegistry}) compose the binder explicitly.
 */
final class RequestBinder
{
    private readonly ?Validator $validator;

    private readonly RuleRegistry $defaultRegistry;

    public function __construct(?Validator $validator = null)
    {
        $this->validator = $validator;
        $this->defaultRegistry = new RuleRegistry();
    }

    private function resolveValidator(): Validator
    {
        return $this->validator ?? new Validator($this->defaultRegistry);
    }

    /**
     * Validate the request's body (JSON priority, form fallback) and
     * build a DTO instance. Same priority rule as the pre-split
     * {@see Request::bind()}: an array `$json` wins over `$form`; a
     * missing / non-array `$json` falls through to `$form`.
     *
     * @template T of object
     * @param Request $request Source request — its `$json` and `$form`
     *        properties are read but never mutated.
     * @param class-string<T> $dtoClass Target DTO class.
     * @return T Validated + populated DTO.
     * @throws \Framework\Validation\ValidationException If validation fails.
     */
    public function bind(Request $request, string $dtoClass): object
    {
        $data = $this->extractBindData($request);
        return $this->resolveValidator()->validate($dtoClass, $data);
    }

    /**
     * Validate caller-supplied data (ignoring the request body) and
     * build a DTO instance. Useful for partial-update endpoints
     * where the payload is shaped by a higher layer.
     *
     * @template T of object
     * @param Request $request Source request — accepted so the API
     *        mirrors {@see self::bind()} and so callers can substitute
     *        a single binder; not consulted.
     * @param array<string, mixed> $data DTO payload to validate.
     * @param class-string<T> $dtoClass Target DTO class.
     * @return T Validated + populated DTO.
     * @throws \Framework\Validation\ValidationException If validation fails.
     */
    public function bindWith(Request $request, array $data, string $dtoClass): object
    {
        unset($request);
        return $this->resolveValidator()->validate($dtoClass, $data);
    }

    /**
     * @return array<string, mixed>
     */
    private function extractBindData(Request $request): array
    {
        $json = $request->json();
        if (is_array($json)) {
            /** @var array<string, mixed> $json */
            return $json;
        }
        $form = $request->form();
        if (is_array($form)) {
            /** @var array<string, mixed> $form */
            return $form;
        }
        return [];
    }
}
