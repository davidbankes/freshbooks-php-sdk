<?php

declare(strict_types=1);

namespace amcintosh\FreshBooks;

use Http\Client\Common\HttpMethodsClient;
use Http\Client\Common\Plugin;
use Http\Client\Common\Plugin\BaseUriPlugin;
use Http\Client\Common\Plugin\HeaderDefaultsPlugin;
use Http\Client\Common\PluginClientFactory;
use Http\Discovery\HttpClientDiscovery;
use Http\Discovery\Psr17FactoryDiscovery;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use amcintosh\FreshBooks\Exception\FreshBooksClientConfigException;
use amcintosh\FreshBooks\Model\AuthorizationToken;
use amcintosh\FreshBooks\Model\Client;
use amcintosh\FreshBooks\Model\ClientList;
use amcintosh\FreshBooks\Model\Expense;
use amcintosh\FreshBooks\Model\ExpenseList;
use amcintosh\FreshBooks\Model\Identity;
use amcintosh\FreshBooks\Model\Invoice;
use amcintosh\FreshBooks\Model\InvoiceList;
use amcintosh\FreshBooks\Model\Payment;
use amcintosh\FreshBooks\Model\PaymentList;
use amcintosh\FreshBooks\Model\Tax;
use amcintosh\FreshBooks\Model\TaxList;
use amcintosh\FreshBooks\Resource\AccountingResource;
use amcintosh\FreshBooks\Resource\AuthResource;

class FreshBooksClient
{
    private ClientInterface $httpClient;
    private RequestFactoryInterface $requestFactoryInterface;
    private StreamFactoryInterface $streamFactoryInterface;

    private FreshBooksClientConfig $config;

    public function __construct(string $clientId, $config)
    {
        $this->config = $config;
        $this->config->clientId = $clientId;
        $this->httpClient = $this->createHttpClient();
    }

    /**
     * Get the current config.
     *
     * @return FreshBooksClientConfig
     */
    public function getConfig(): FreshBooksClientConfig
    {
        return $this->config;
    }

    protected function getHeaders(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->config->accessToken,
            'User-Agent' => $this->config->getUserAgent(),
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
    }

    protected function createHttpClient(): HttpMethodsClient
    {
        $plugins = array(
            new BaseUriPlugin(Psr17FactoryDiscovery::findUriFactory()->createUri($this->config->apiBaseUrl)),
            new HeaderDefaultsPlugin($this->getHeaders()),
        );

        $pluginClient = (new PluginClientFactory())->createClient(
            HttpClientDiscovery::find(),
            $plugins
        );

        return new HttpMethodsClient(
            $pluginClient,
            Psr17FactoryDiscovery::findRequestFactory(),
            Psr17FactoryDiscovery::findStreamFactory()
        );
    }

    /**
     * getAuthRequestUri
     *
     * @param  mixed $scopes
     * @return string
     */
    public function getAuthRequestUri(array $scopes = null): string
    {
        if (is_null($this->config->redirectUri)) {
            throw new FreshBooksClientConfigException('redirectUri must be configured');
        }
        $params = [
            'client_id' => $this->config->clientId,
            'response_type' => "code",
            'redirect_uri' => $this->config->redirectUri
        ];
        if (!is_null($scopes)) {
            $params["scope"] = implode(' ', $scopes);
        }
        return $this->config->authBaseUrl . '/oauth/authorize?' . http_build_query($params);
    }

    /**
     * Make call to FreshBooks OAuth /token endpoint to fetch access_token and refresh_tokens.
     *
     * @param  mixed $grantType The grant type to use
     * @param  mixed $codeType The type of code to use
     * @param  mixed $code The code to use
     * @return AuthorizationToken Object containing the access toekn, refresh token, and expiry details.
     */
    protected function getToken(string $grantType, string $codeType, string $code): AuthorizationToken
    {
        if (is_null($this->config->redirectUri)) {
            throw new FreshBooksClientConfigException('redirectUri must be configured');
        }
        if (is_null($this->config->clientSecret)) {
            throw new FreshBooksClientConfigException('clientSecret must be configured');
        }
        $payload = [
            'client_id' => $this->config->clientId,
            'client_secret' => $this->config->clientSecret,
            'redirect_uri' => $this->config->redirectUri,
            'grant_type' => $grantType,
            $codeType => $code,
        ];
        $tokenDetails = (new AuthResource($this->httpClient))->getToken($payload);
        $this->config->accessToken = $tokenDetails->accessToken;
        $this->config->refreshToken = $tokenDetails->refreshToken;
        $this->config->tokenExpiresAt = $tokenDetails->getExpiresAt();
        $this->httpClient = $this->createHttpClient();
        return $tokenDetails;
    }

    /**
     * Makes a call to the FreshBooks token URL to get an access_token.
     *
     * This requires the access_grant code obtained after the user is redirected by the authorization
     * step. See {@see FreshBooksClient::getAuthRequestUri()}.
     *
     * This call sets the `accessToken`, `refreshToken`, and `tokenExpiresAt` properties on the
     * FreshBooksClientConfig instance (see {@see FreshBooksClient::getConfig()})
     * and also returns those values in an AuthorizationToken object.
     *
     * @param  mixed $code access grant code from the authorization redirect
     * @return AuthorizationToken Object containing the access toekn, refresh token, and expiry details.
     */
    public function getAccessToken(string $code): AuthorizationToken
    {
        return $this->getToken('authorization_code', 'code', $code);
    }

    /**
     * Makes a call to the FreshBooks token URL to refresh an access_token.
     *
     * If `refreshToken` is provided, it will call to refresh it, otherwise it will use the
     * `refreshToken` on the FreshBooksClientConfig instance.
     *
     * This call sets the `accessToken`, `refreshToken`, and `tokenExpiresAt`  properties on the
     * FreshBooksClientConfig instance (see {@see FreshBooksClient::getConfig()})
     * and also returns those values in an AuthorizationToken object.
     *
     * @param  mixed $refreshToken (Optional) Existing refresh token
     * @return AuthorizationToken Object containing the access toekn, refresh token, and expiry details.
     */
    public function refreshAccessToken(string $refreshToken = null): AuthorizationToken
    {
        if (is_null($refreshToken)) {
            $refreshToken = $this->config->refreshToken;
        }
        if (is_null($refreshToken)) {
            throw new FreshBooksClientConfigException('refreshToken must be configured or provided');
        }

        return $this->getToken('refresh_token', 'refresh_token', $refreshToken);
    }

    /**
     * The identity details of the currently authenticated user.
     *
     * @link https://www.freshbooks.com/api/me_endpoint
     * @return Identity
     */
    public function currentUser(): Identity
    {
        return (new AuthResource($this->httpClient))->getMeEndpoint();
    }

    /**
     * FreshBooks clients resource with calls to get, list, create, update, delete
     *
     * @return AccountingResource
     */
    public function clients(): AccountingResource
    {
        return new AccountingResource($this->httpClient, 'users/clients', Client::class, ClientList::class);
    }


    /**
     * FreshBooks expenses resource with calls to get, list, create, update, delete
     *
     * @return AccountingResource
     */
    public function expenses(): AccountingResource
    {
        return new AccountingResource($this->httpClient, 'expenses/expenses', Expense::class, ExpenseList::class);
    }

    /**
     * FreshBooks invoices resource with calls to get, list, create, update, delete
     *
     * @return AccountingResource
     */
    public function invoices(): AccountingResource
    {
        return new AccountingResource(
            $this->httpClient,
            'invoices/invoices',
            Invoice::class,
            InvoiceList::class,
            deleteViaUpdate: false
        );
    }

    /**
     * FreshBooks payments resource with calls to get, list, create, update, delete.
     *
     * @return AccountingResource
     */
    public function payments(): AccountingResource
    {
        return new AccountingResource($this->httpClient, 'payments/payments', Payment::class, PaymentList::class);
    }

    /**
     * FreshBooks taxes resource with calls to get, list, create, update, delete.
     *
     * @return AccountingResource
     */
    public function taxes(): AccountingResource
    {
        return new AccountingResource($this->httpClient, 'taxes/taxes', Tax::class, TaxList::class);
    }
}
