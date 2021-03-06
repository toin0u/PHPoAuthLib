<?php

/**
 * OAuth service factory.
 *
 * PHP version 5.4
 *
 * @category   OAuth
 * @author     David Desberg <david@daviddesberg.com>
 * @copyright  Copyright (c) 2013 The authors
 * @license    http://www.opensource.org/licenses/mit-license.html  MIT License
 */

namespace OAuth;

use OAuth\Common\Service\ServiceInterface;
use OAuth\Common\Consumer\Credentials;
use OAuth\Common\Storage\TokenStorageInterface;
use OAuth\Common\Http\Client\ClientInterface;
use OAuth\Common\Http\Client\StreamClient;
use OAuth\Common\Exception\Exception;
use OAuth\OAuth1\Signature\Signature;

class ServiceFactory
{
    /** @var ClientInterface */
    protected $httpClient;

    /** @var array */
    protected $serviceClassMap = array(
        'OAuth1' => array(),
        'OAuth2' => array()
    );

    /**
     * @param ClientInterface $httpClient
     *
     * @return ServiceFactory
     */
    public function setHttpClient(ClientInterface $httpClient)
    {
        $this->httpClient = $httpClient;

        return $this;
    }

    /**
     * Register a custom service to classname mapping.
     *
     * @param string $serviceName Name of the service
     * @param string $className   Class to instantiate
     *
     * @return ServiceFactory
     *
     * @throws Exception If the class is nonexistent or does not implement a valid ServiceInterface
     */
    public function registerService($serviceName, $className)
    {
        if (!class_exists($className)) {
            throw new Exception(sprintf('Service class %s does not exist.', $className));
        }

        $reflClass = new \ReflectionClass($className);

        foreach (array('OAuth2', 'OAuth1') as $version) {
            if ($reflClass->implementsInterface('OAuth\\' . $version . '\\Service\\ServiceInterface')) {
                $this->serviceClassMap[$version][ucfirst($serviceName)] = $className;

                return $this;
            }
        }

        throw new Exception(sprintf('Service class %s must implement ServiceInterface.', $className));
    }

    /**
     * @param string                $serviceName Name of service to create
     * @param Credentials           $credentials
     * @param TokenStorageInterface $storage
     * @param array|null            $scopes      If creating an oauth2 service, array of scopes
     *
     * @return ServiceInterface
     *
     * @throws Exception
     */
    public function createService(
        $serviceName,
        Credentials $credentials,
        TokenStorageInterface $storage,
        $scopes = array()
    ) {
        if (!$this->httpClient) {
            // for backwards compatibility.
            $this->httpClient = new StreamClient();
        }

        $serviceName = ucfirst($serviceName);
        $v2ClassName = isset($this->serviceClassMap['OAuth2'][$serviceName])
            ? $this->serviceClassMap['OAuth2'][$serviceName]
            : "\\OAuth\\OAuth2\\Service\\$serviceName";
        $v1ClassName = isset($this->serviceClassMap['OAuth1'][$serviceName])
            ? $this->serviceClassMap['OAuth1'][$serviceName]
            : "\\OAuth\\OAuth1\\Service\\$serviceName";

        // if an oauth2 version exists, prefer it
        if (class_exists($v2ClassName)) {
            // resolve scopes
            $resolvedScopes = array();
            $reflClass = new \ReflectionClass($v2ClassName);
            $constants = $reflClass->getConstants();

            foreach ($scopes as $scope) {
                $key = strtoupper('SCOPE_' . $scope);
                // try to find a class constant with this name
                if (array_key_exists($key, $constants)) {
                    $resolvedScopes[] = $constants[$key];
                } else {
                    $resolvedScopes[] = $scope;
                }
            }

            return new $v2ClassName($credentials, $this->httpClient, $storage, $resolvedScopes);
        }

        if (class_exists($v1ClassName)) {
            if (!empty($scopes)) {
                throw new Exception(
                    'Scopes passed to ServiceFactory::createService but an OAuth1 service was requested.'
                );
            }
            $signature = new Signature($credentials);

            return new $v1ClassName($credentials, $this->httpClient, $storage, $signature);
        }

        return null;
    }
}
