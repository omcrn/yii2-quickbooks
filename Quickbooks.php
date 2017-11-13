<?php
/**
 * Created by PhpStorm.
 * User: beqa
 * Date: 11/13/17
 * Time: 12:25 PM
 */

namespace omcrn\quickbooks;


use QuickBooksOnline\API\DataService\DataService;
use yii\base\Component;

class Quickbooks extends Component
{
    public $authMode = 'oauth2';
    public $clientID;
    public $clientSecret;
    public $accessTokenKey;
    public $refreshTokenKey;
    public $realmID;
    public $baseUrl;
    /**
     * @var DataService
     */
    private $dataService;
    public $dataServiceConfig = null;


    public function init()
    {
        parent::init();
        if ($this->dataServiceConfig === null){
            $this->dataServiceConfig = [
                'auth_mode'             => $this->authMode,
                'ClientID'              => $this->clientID,
                'ClientSecret'          => $this->clientSecret,
                'accessTokenKey'        => $this->accessTokenKey,
                'refreshTokenKey'       => $this->refreshTokenKey,
                'QBORealmID'            => $this->realmID,
                'baseUrl'               => $this->baseUrl,
            ];
        }
    }
}