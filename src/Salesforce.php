<?php

namespace Frankkessler\Salesforce;

use CommerceGuys\Guzzle\Oauth2\GrantType\RefreshToken;
use CommerceGuys\Guzzle\Oauth2\Oauth2Client;
use Exception;
use Frankkessler\Salesforce\Repositories\TokenRepository;

class Salesforce
{
    public $oauth2Client;

    protected $config;

    private $bulk_api;

    public function __construct($config = null)
    {
        $this->config = $config;
        //Allow custom config to be applied through the constructor
        SalesforceConfig::setInitialConfig($config);

        $this->log('debug','Salesforce::__construct - STARTED');

        $this->repository = new TokenRepository();

        $base_uri = 'https://'.SalesforceConfig::get('salesforce.api.domain').SalesforceConfig::get('salesforce.api.base_uri');

        $client_config = [
            'base_uri' => $base_uri,
            'auth' => 'oauth2',
        ];

        //allow for override of default oauth2 handler
        if(isset($config['handler'])){
            $client_config['handler'] = $config['handler'];
        }

        $this->log('debug','Salesforce::__construct - BEFORE OAUTH CLIENT');

        if(!$this->oauth2Client) {
            $this->oauth2Client = new Oauth2Client($client_config);
        }

        $this->log('debug','Salesforce::__construct - BEFORE GET TOKEN');

        //If access_token or refresh_token are NOT supplied through constructor, pull them from the repository
        if (!SalesforceConfig::get('salesforce.oauth.access_token') || !SalesforceConfig::get('salesforce.oauth.refresh_token')) {
            $this->token_record = $this->repository->store->getTokenRecord();
            SalesforceConfig::set('salesforce.oauth.access_token', $this->token_record->access_token);
            SalesforceConfig::set('salesforce.oauth.refresh_token', $this->token_record->refresh_token);
        }

        $access_token = SalesforceConfig::get('salesforce.oauth.access_token');
        $refresh_token = SalesforceConfig::get('salesforce.oauth.refresh_token');

        $this->log('debug','Salesforce::__construct - BEFORE SET TOKEN');
        //Set access token and refresh token in Guzzle oauth client
        $this->oauth2Client->setAccessToken($access_token, $access_token_type = 'Bearer');
        $this->oauth2Client->setRefreshToken($refresh_token);
        $refresh_token_config = [
            'client_id'     => SalesforceConfig::get('salesforce.oauth.consumer_token'),
            'client_secret' => SalesforceConfig::get('salesforce.oauth.consumer_secret'),
            'refresh_token' => $refresh_token,
            'token_url'     => 'https://'.SalesforceConfig::get('salesforce.oauth.domain').SalesforceConfig::get('salesforce.oauth.token_uri'),
            'auth_location' => 'body',
        ];
        $this->log('debug','Salesforce::__construct - BEFORE REFRESH TOKEN');
        $this->oauth2Client->setRefreshTokenGrantType(new RefreshToken($refresh_token_config));
    }

    /**
     * Get full sObject
     * @param $id
     * @param $type
     * @return array|mixed
     */

    public function getObject($id, $type)
    {
        return $this->call_api('get', 'sobjects/'.$type.'/'.$id);
    }

    /**
     * Create sObject
     * @param string $type
     * @param array $data
     * @return array|mixed
     */

    public function createObject($type, $data)
    {
        return $this->call_api('post', 'sobjects/'.$type, [
            'http_errors' => false,
            'body'        => json_encode($data),
            'headers'     => [
                'Content-type' => 'application/json',
            ],
        ]);
    }

    /**
     * Update sObject
     * @param string $id
     * @param string $type
     * @param array $data
     * @return array|mixed
     */

    public function updateObject($id, $type, $data)
    {
        if (!$id && isset($data['id'])) {
            $id = $data['id'];
            unset($data['id']);
        } elseif (isset($data['id'])) {
            unset($data['id']);
        }

        if (!$id || !$type || !$data) {
            return [];
        }

        return $this->call_api('patch', 'sobjects/'.$type.'/'.$id, [
            'http_errors' => false,
            'body'        => json_encode($data),
            'headers'     => [
                'Content-type' => 'application/json',
            ],
        ]);
    }

    public function deleteObject($id, $type)
    {
        if (!$type || !$id) {
            return [];
        }

        return $this->call_api('delete', 'sobjects/'.$type.'/'.$id);
    }

    public function externalGetObject($external_field_name, $external_id, $type)
    {
        return $this->call_api('get', 'sobjects/'.$type.'/'.$external_field_name.'/'.$external_id);
    }

    public function externalUpsertObject($external_field_name, $external_id, $type, $data)
    {
        $result = $this->call_api('patch', 'sobjects/'.$type.'/'.$external_field_name.'/'.$external_id, [
            'http_errors' => false,
            'body'        => json_encode($data),
            'headers'     => [
                'Content-type' => 'application/json',
            ],
        ]);

        return $result;
    }

    public function query($query)
    {
        return $this->call_api('get', 'query/?q='.urlencode($query));
    }

    public function queryFollowNext($query)
    {
        return $this->_queryFollowNext('query', $query);
    }

    public function queryAll($query)
    {
        return $this->call_api('get', 'queryAll/?q='.urlencode($query));
    }

    public function queryAllFollowNext($query)
    {
        return $this->_queryFollowNext('queryAll', $query);
    }

    protected function _queryFollowNext($query_type, $query, $url = null)
    {
        //next url has not been supplied
        if (is_null($url)) {
            $result = $this->call_api('get', $query_type.'/?q='.urlencode($query));
        } else {
            $result = $this->rawGetRequest($url);
        }

        if ($result && isset($result['records']) && $result['records']) {
            if (isset($result['nextRecordsUrl']) && $result['nextRecordsUrl']) {
                $new_result = $this->_queryFollowNext($query_type, $query, $result['nextRecordsUrl']);
                if ($new_result && isset($new_result['records'])) {
                    $result['records'] = array_merge($result['records'], $new_result['records']);
                }
            }
        }

        return $result;
    }

    public function search($query)
    {
        return $this->call_api('get', 'search/?q='.urlencode($query));
    }

    public function getCustomRest($uri)
    {
        $url = 'https://'.SalesforceConfig::get('salesforce.api.domain').'/services/apexrest/'.$uri;

        return $this->rawgetRequest($url);
    }

    public function postCustomRest($uri, $data)
    {
        $url = 'https://'.SalesforceConfig::get('salesforce.api.domain').'/services/apexrest/'.$uri;

        return $this->rawPostRequest($url, $data);
    }

    public function rawGetRequest($request_string)
    {
        return $this->call_api('get', $request_string);
    }

    public function rawPostRequest($request_string, $data)
    {
        return $this->call_api('post', $request_string, [
            'http_errors' => false,
            'body'        => json_encode($data),
            'headers'     => [
                'Content-type' => 'application/json',
            ],
        ]);
    }

    /**
     * @return Bulk
     */
    public function bulk()
    {
        if(!$this->bulk_api){
            $this->bulk_api = new Bulk($this->config);
        }

        return $this->bulk_api;
    }

    protected function call_api($method, $url, $options = [], $debug_info = [])
    {
        try {
            if (is_null($options)) {
                $options = [];
            }

            $options['http_errors'] = false;

            $response = $this->oauth2Client->{$method}($url, $options);

            /* @var $response \GuzzleHttp\Psr7\Response */

            $response_code = $response->getStatusCode();

            $data = [
                'operation' => '',
                'success' => false,
                'message_string' => '',
                'http_status' => 500,

            ];

            if ($response_code == 200) {
                $data = array_replace($data,json_decode((string) $response->getBody(), true));
            } elseif ($response_code == 201) {
                $data = array_replace($data,json_decode((string) $response->getBody(), true));

                $data['operation'] = 'create';

                if (isset($data['id'])) {
                    //make responses more uniform by setting a newly created id as an Id field like you would see from a get
                    $data['Id'] = $data['id'];
                }
            } elseif ($response_code == 204) {
                if (strtolower($method) == 'delete') {
                    $data = array_merge($data, [
                        'success'   => true,
                        'operation' => 'delete',
                    ]);
                } else {
                    $data = array_merge($data, [
                        'success'   => true,
                        'operation' => 'update',
                    ]);
                }
            } else {
                $full_data = json_decode((string) $response->getBody(), true);
                if(count($full_data) > 1){
                    $data = array_merge($data, $full_data);
                }else{
                    $data = array_merge($data, current($full_data));
                }


                if ($data && isset($data['message'])) {
                    $data['message_string'] = $data['message'];
                } elseif (!$data) {
                    $data['message_string'] = (string) $response->getBody();
                }

                $data['http_status'] = $response_code;
                $data['success'] = false;

                $data = array_merge($debug_info, $data);
            }


            if (isset($data) && $data) {
                $this->updateAccessToken($this->oauth2Client->getAccessToken()->getToken());

                return $data;
            }
        } catch (Exception $e) {
            $data['message_string'] = $e->getMessage();
            $data['file'] = $e->getFile().':'.$e->getLine();
            $data['http_status'] = 500;
            $data['success'] = false;
            $data = array_merge($debug_info, $data);

            return $data;
        }

        return [];
    }

    protected function log($level, $message)
    {
        if($this->config['logger'] instanceof \Psr\Log\LoggerInterface && is_callable([$this->config['logger'], $level])){
            return call_user_func([$this->config['logger'], $level],$message);
        }else{
            return null;
        }
    }

    protected function updateAccessToken($current_access_token)
    {
        if ($current_access_token != SalesforceConfig::get('salesforce.oauth.access_token')) {
            $this->repository->store->setAccessToken($current_access_token);
            SalesforceConfig::set('salesforce.oauth.access_token', $current_access_token);
        }
    }
}
