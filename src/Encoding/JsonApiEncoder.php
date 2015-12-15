<?php
namespace Czim\JsonApi\Encoding;

use Czim\JsonApi\Contracts\SchemaProviderInterface;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Support\Arrayable;
use Neomerx\JsonApi\Contracts\Encoder\EncoderInterface;
use Neomerx\JsonApi\Document\Error as JsonApiError;
use Neomerx\JsonApi\Encoder\EncoderOptions;
use Neomerx\JsonApi\Schema\Link;


class JsonApiEncoder
{

    /**
     * @var Container
     */
    protected $app;

    /**
     * @var SchemaProviderInterface
     */
    protected $schemaProvider;


    /**
     * @param Container               $app
     * @param SchemaProviderInterface $schemaProvider
     */
    public function __construct(Container $app, SchemaProviderInterface $schemaProvider = null)
    {
        if (is_null($schemaProvider)) {
            $schemaProvider = app(SchemaProviderInterface::class);
        }

        $this->app            = $app;
        $this->schemaProvider = $schemaProvider;
    }


    /**
     * Encodes data as valid JSON API response and returns it
     *
     * @param mixed $data
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function response($data)
    {
        return response( $this->encode($data) )
            ->setTtl( config('jsonapi.default_ttl', 60) );
    }

    /**
     * Encodes data as valid JSON API output
     *
     * @param mixed $data
     * @return $this
     */
    public function encode($data)
    {
        // todo based on what data is provided,
        // the encoding should be handled..
        // we're expecting something that implements the resourceinterface

        // todo handle meta properly


        return $this->getEncoder()
            ->withLinks([
                Link::SELF => new Link( $this->getUrlToSelf() ),
            ])
            //->withMeta( (EloquentSchema::$meta ?: null) )
            ->encodeData($data);
    }

    /**
     * Encodes errors as JSON-API error response
     *
     * @param array|Arrayable $errors
     * @param int             $status   HTTP status code for response
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function errors($errors, $status = 500)
    {
        $errors = $this->normalizeErrors($errors, $status);

        return response(
            $this->getEncoder()->encodeErrors($errors),
            $status
        );
    }

    /**
     * Normalizes error or set of errors to standard error object array
     * Flattens nested arrays to a single dimension
     *
     * @param array|Arrayable $errors
     * @param int             $status       http error status to set when creating error objects
     * @param string          $parentKey    if recursive call, the key of the array's parent
     * @return \Neomerx\JsonApi\Document\Error[]
     */
    protected function normalizeErrors($errors, $status = null, $parentKey = null)
    {
        if ( ! is_null($status)) {
            $status = (string) $status;
        }

        if (is_a($errors, Arrayable::class)) {
            $errors = $errors->toArray();
        }

        if ( ! is_array($errors)) {
            $errors = (array) $errors;
        }

        $normalizedErrors = [];

        foreach ($errors as $key => $error) {

            if ($error instanceof JsonApiError) {
                $normalizedErrors[] = $error;
                continue;
            }

            if (is_array($error)) {
                $normalizedErrors = array_merge($normalizedErrors, $this->normalizeErrors($error, $status, $key));
                continue;
            }

            // store non-numeric key as detail, since it might be the offending field
            // for typical laravel error bag output
            $detail = ( ! is_numeric($key)) ? $key : (( ! is_numeric($parentKey)) ? $parentKey : null );

            $normalizedErrors[] = new JsonApiError(null, null, $status, null, $error, $detail);
        }

        return $normalizedErrors;
    }

    /**
     * @return EncoderInterface
     */
    protected function getEncoder()
    {
        return Encoder::instance(
            $this->getEncoderSchemaMapping(),
            $this->getEncoderOptions()
        );
    }

    /**
     * returns the SchemaProvider/mapping
     *
     * @return array
     */
    protected function getEncoderSchemaMapping()
    {
        return $this->schemaProvider->getSchemaMapping();
    }

    /**
     * Returns Encoder options to inject into the Encoder
     *
     * @return EncoderOptions
     */
    protected function getEncoderOptions()
    {
        return new EncoderOptions(
            config('jsonapi.encoding.encoder_options', JSON_UNESCAPED_SLASHES),
            $this->getUrlToRoot()
        );
    }


    /**
     * Returns relative URL to the encoded content's (current request) self
     *
     * @return string
     */
    protected function getUrlToSelf()
    {
        return join('/', array_slice($this->app->make('request')->segments(), 1));
    }

    /**
     * Returns URL to 'root' of API
     *
     * @return string
     */
    protected function getUrlToRoot()
    {
        $baseUrl = config('jsonapi.base_url');
        $basePath = '/' . ltrim(config('jsonapi.base_url_path'), '/');

        if ( ! empty($baseUrl)) return $baseUrl . $basePath;

        return $this->app->make('request')->root() . $basePath;
    }


}
