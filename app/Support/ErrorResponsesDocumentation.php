<?php

declare(strict_types=1);

namespace App\Support;

use Dedoc\Scramble\Support\Generator\Header;
use Dedoc\Scramble\Support\Generator\MediaType;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Reference;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\ArrayType;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;

final class ErrorResponsesDocumentation
{
    private const string REQUEST_ID = '9f1c2b7e-3c1a-4b8e-9d52-1c0a7e5d1f10';

    public function __invoke(OpenApi $openApi): void
    {
        $this->registerSchemas($openApi);

        foreach (['ValidationException', 'AuthenticationException', 'AuthorizationException', 'ModelNotFoundException'] as $name) {
            $openApi->components->removeResponse($name);
        }

        foreach ($openApi->paths as $path) {
            foreach ($path->operations as $operation) {
                $this->documentOperation($openApi, $operation);
            }
        }
    }

    private function registerSchemas(OpenApi $openApi): void
    {
        $base = (new ObjectType)
            ->addProperty('message', (new StringType)->setDescription('Fixed text per code, in English.'))
            ->addProperty('code', (new StringType)->setDescription('Stable code for clients.')->enum([
                'validation_error', 'unauthenticated', 'forbidden', 'not_found', 'method_not_allowed',
                'conflict', 'payload_too_large', 'too_many_requests', 'bad_request', 'server_error',
            ]))
            ->addProperty('request_id', (new StringType)->format('uuid')->setDescription('Same value as the `X-Request-Id` header.'))
            ->setRequired(['message', 'code', 'request_id']);

        $validation = $base->clone()
            ->addProperty('errors', (new ObjectType)
                ->setDescription('Messages per field. Only present on 422.')
                ->additionalProperties((new ArrayType)->setItems(new StringType)))
            ->addRequired(['errors']);

        $openApi->components->addSchema('Error', Schema::fromType($base)->setTitle('Error'));
        $openApi->components->addSchema('ValidationError', Schema::fromType($validation)->setTitle('ValidationError'));
    }

    private function documentOperation(OpenApi $openApi, Operation $operation): void
    {
        $present = $this->presentCodes($operation);
        $isPriceStore = $operation->operationId === 'v1.products.prices.store';
        $hasBody = $operation->requestBodyObject !== null;

        $operation->responses = array_values(array_filter(
            $operation->responses ?? [],
            fn (Response|Reference $response): bool => ! in_array($this->codeOf($response), [400, 401, 403, 404, 409, 413, 422, 429], true),
        ));

        if ($hasBody) {
            $operation->addResponse($this->error($openApi, 400, 'Malformed JSON body.', 'bad_request', 'Bad request.'));
        }

        if (in_array(401, $present, true)) {
            $operation->addResponse($this->error($openApi, 401, 'Missing, invalid, expired or revoked token.', 'unauthenticated', 'Unauthenticated.'));
        }

        if (in_array(403, $present, true)) {
            $operation->addResponse($this->error($openApi, 403, 'Insufficient role or token ability.', 'forbidden', 'This action is unauthorized.'));
        }

        if (in_array(404, $present, true)) {
            $operation->addResponse($this->error($openApi, 404, 'Resource does not exist, was deleted, or the identifier is not numeric.', 'not_found', 'Resource not found.'));
        }

        if ($isPriceStore) {
            $operation->addResponse($this->error($openApi, 409, 'A price already exists for that product and currency.', 'conflict', 'A price already exists for this product and currency.'));
        }

        if ($hasBody) {
            $operation->addResponse($this->error($openApi, 413, 'The body exceeds `API_MAX_PAYLOAD_KB`.', 'payload_too_large', 'Payload too large.'));
        }

        if (in_array(422, $present, true)) {
            $operation->addResponse($this->error(
                $openApi, 422, 'Validation error.', 'validation_error', 'The given data was invalid.',
                ['price' => ['The price field must be at least 0.01.']],
            ));
        }

        $operation->addResponse($this->tooManyRequests($openApi));

        usort($operation->responses, fn (Response|Reference $a, Response|Reference $b): int => $this->codeOf($a) <=> $this->codeOf($b));
    }

    /**
     * @return list<int>
     */
    private function presentCodes(Operation $operation): array
    {
        return array_values(array_filter(array_map(
            fn (Response|Reference $response): int => $this->codeOf($response),
            $operation->responses ?? [],
        )));
    }

    private function codeOf(Response|Reference $response): int
    {
        $resolved = $response instanceof Reference ? $response->resolve() : $response;

        return $resolved instanceof Response ? (int) $resolved->code : 0;
    }

    /**
     * @param  array<string, list<string>>|null  $errors
     */
    private function error(OpenApi $openApi, int $status, string $description, string $code, string $message, ?array $errors = null): Response
    {
        $schema = $errors === null ? 'Error' : 'ValidationError';
        $example = ['message' => $message, 'code' => $code];

        if ($errors !== null) {
            $example['errors'] = $errors;
        }

        $example['request_id'] = self::REQUEST_ID;

        return Response::make($status)
            ->setDescription($description)
            ->addContent('application/json', new MediaType(
                schema: new Reference('schemas', $schema, $openApi->components),
                example: $example,
            ));
    }

    private function tooManyRequests(OpenApi $openApi): Response
    {
        return $this->error($openApi, 429, 'Rate limit exceeded.', 'too_many_requests', 'Too many requests.')
            ->addHeader('Retry-After', new Header('Seconds until the request can be retried.', schema: Schema::fromType(new IntegerType)))
            ->addHeader('X-RateLimit-Limit', new Header('Requests allowed per minute.', schema: Schema::fromType(new IntegerType)))
            ->addHeader('X-RateLimit-Remaining', new Header('Requests remaining in the current window.', schema: Schema::fromType(new IntegerType)));
    }
}
