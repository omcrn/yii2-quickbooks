<?php

namespace omcrn\quickbooks;

use Exception;
use QuickBooksOnline\API\DataService\DataService;
use QuickBooksOnline\API\Facades\Customer;
use QuickBooksOnline\API\Facades\Invoice;
use Yii;
use yii\base\Component;
use yii\base\InvalidConfigException;

class Quickbooks extends Component
{
    public $authMode;
    public $realmId;
    public $baseUrl;
    public $discoveryDocumentUrl;
    public $clientId;
    public $clientSecret;
    public $oauthScope;
    public $accessToken;
    public $refreshToken;
    /**
     * @var DataService
     */
    private $dataService;


    public function init()
    {
        parent::init();
        $ks = Yii::$app->keyStorage;
        $this->accessToken = $ks->get('quickbooks.access-token');
        $this->refreshToken = $ks->get('quickbooks.refresh-token');
        $this->dataServiceInit();
    }

    private function getDataServiceConfig(){
        return [
            'auth_mode'         => $this->authMode,
            'ClientID'          => $this->clientId,
            'ClientSecret'      => $this->clientSecret,
            'baseUrl'           => $this->baseUrl,
            'QBORealmID'        => $this->realmId,
            'accessTokenKey'    => $this->accessToken,
            'refreshTokenKey'   => $this->refreshToken
        ];
    }

    public function dataServiceInit(){
        $dataService = DataService::Configure($this->getDataServiceConfig());
        if (!$dataService)
            throw new InvalidConfigException("Problem while initializing DataService.\n");
        $this->dataService = $dataService;
    }

    // two-step oauth2 process
    // tokens are saved in db and in instance and new DataService is instantiated after a successful connect
    public function connect(){
        $ks = Yii::$app->keyStorage;
        $session = Yii::$app->session;
        var_dump(Yii::$app->request->get());

        // discovery document
        $discoveryDocument = json_decode(file_get_contents($this->discoveryDocumentUrl), true);

        // ready the params, just for readability

        //სადაც იგზავნება GET პირველ ნაბიჯზე. discovery document-დან მომაქვს
        $authRequestUrl = $discoveryDocument['authorization_endpoint']; //$configs['authorizationRequestUrl'];
        //სადაც იგზავნება POST მეორე ნაბიჯზე, ესეც discovery document-დან მომაქვს
        $tokenEndPointUrl = $discoveryDocument['token_endpoint']; //$configs['tokenEndPointUrl'];

        $redirectUri = 'http://victorian-society.dev' . $ks->get('quickbooks.redirect-url');

        // დოკუმენტაციაში წერია, რომ QBO-ს მიმდინარე ვერსიისათვის ყოველთვის იქნება code, მაინც გავიტანე ცვლადში,
        // თუ ოდესმე შეიცვლება, მარტივად მივაგნებ
        $responseType = 'code';

        //დოკუმენტაციის მიხედვით აქ უნდა იყოს authorization_code
        $grantType= 'authorization_code';

        //თუ არ მოყვა code ესე იგი oauth2-ის პირველ ნაბიჯზე ვარ
        $code = Yii::$app->request->get('code');
        if (!$code)
        {
            //სესიაში შევინახოთ state მეორე ნაბიჯზე მოსულთან შესადარებლად
            $session->set('oauth_state', rand());

            $authUrl = $discoveryDocument['authorization_endpoint'] . '?client_id=' . $this->clientId .
                '&response_type=' . $responseType . '&scope=' . $this->oauthScope . '&redirect_uri=' .
                $redirectUri . '&state=' . $session->get('oauth_state');
            header("Location: ".$authUrl);
            exit();
        }
        //თუ მოყვა code ესე იგი oauth2-ის მეორე ნაბიჯზე ვარ
        else
        {
            //ამოვიღოთ state და შევადაროთ სესიაში შენახულს, თუ არ დაემთხვა, ესე იგი რაღაცა ნიტოა
            if(strcmp($session->get('oauth_state'), $_GET['state']) != 0){
                throw new Exception("The state is not correct from Intuit Server. Consider your app is hacked.");
            }

            // POST and wait for JSON response
            $curl = curl_init();
            $header = [
                'Accept: application/json',
                'Authorization: Basic ' . base64_encode($this->clientId . ':' . $this->clientSecret),
                'Content-Type: application/x-www-form-urlencoded'
            ];
            curl_setopt_array($curl, [
                CURLOPT_POST => 1,
                CURLOPT_RETURNTRANSFER => 1,
                CURLOPT_URL => $tokenEndPointUrl,
                CURLOPT_POSTFIELDS => http_build_query([
                    'grant_type' => $grantType,
                    'code' => $code,
                    'redirect_uri' => $redirectUri
                ]),
                CURLOPT_HTTPHEADER => $header
            ]);
            $result = curl_exec($curl);
            // check for errors, just in case
            if ($curl_error = curl_error($curl)) {
                throw new Exception($curl_error);
            } else {
                $result = json_decode($result, true);
            }
            curl_close($curl);

            // მიღებული ტოკენების შენახვა ბაზაში, key_storage_item ცხრილში
            $ks->set('quickbooks.access-token', $result['access_token']);
            $ks->set('quickbooks.access-token-expires-in', $result['expires_in']);
            $ks->set('quickbooks.refresh-token', $result['refresh_token']);
            $ks->set('quickbooks.refresh-token-expires-in', $result['x_refresh_token_expires_in']);
            $ks->set('quickbooks.realm-id', $_GET['realmId']);

            //დავხუროთ CONNECT-ზე დაჭერით ამომხტარი ავტორიზაციის ფანჯარა
            echo '<script type="text/javascript">
                // refresh davakomentare, cross-originebis da sxva rameebis gamo jobia im fanjaras
                // postMessage mivce da tviton mixedos tavs
                //window.opener.location.href = window.opener.location.href;
                window.opener.postMessage("success", "*");
                window.close();
            </script>';
        }
    }

    // access token expires in an hour, reconnect function revokes it using refresh token
    // refresh token lasts 101 days, after that it is necessary to click he QUICKBOOKS CONNECT button and reauthorize
    // tokens are saved in db and in instance and new DataService is instantiated after a successful reconnect
    public function reconnect(){
        $ks = Yii::$app->keyStorage;

        // if refresh_token is not set, it means the app never connected to quickbooks
        if (!$this->refreshToken){
            throw new Exception('Refresh token not set. You need to click CONNECT QUICKBOOKS button');
        }

        // discovery document
        $discoveryDocument = json_decode(file_get_contents($this->discoveryDocumentUrl), true);

        // ready the params, just for readability

        // the URL for reconnect POST, from discovery document
        $tokenEndPointUrl = $discoveryDocument['token_endpoint'];

        // according to the dcos, this param is always refresh_token for reconnect
        $grantType = 'refresh_token';

        // POST and wait for JSON response
        $curl = curl_init();
        $header = [
            'Accept: application/json',
            'Authorization: Basic ' . base64_encode($this->clientId . ':' . $this->clientSecret),
            'Content-Type: application/x-www-form-urlencoded',
            'Cache-Control: no-cache'
        ];
        curl_setopt_array($curl, [
            CURLOPT_POST => 1,
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_URL => $tokenEndPointUrl,
            CURLOPT_POSTFIELDS => http_build_query([
                'grant_type' => $grantType,
                'refresh_token' => $this->refreshToken
            ]),
            CURLOPT_HTTPHEADER => $header
        ]);
        $result = curl_exec($curl);
        // check for errors, just in case
        if ($curl_error = curl_error($curl)) {
            throw new Exception($curl_error);
        } else {
            $result = json_decode($result, true);
        }
        curl_close($curl);
        // if reponse JSON contains "error" key, it means token is either expired or never set
        if (isset($result['error'])){
            throw new Exception($result['error'] . '. Need to click CONNECT QUICKBOOKS button');
        }

        // save the received tokens in key_storage_item and in the instance
        $this->accessToken = $result['access_token'];
        $ks->set('quickbooks.access-token', $this->accessToken);
        $ks->set('quickbooks.access-token-expires-in', $result['expires_in']);
        $this->refreshToken = $result['refresh_token'];
        $ks->set('quickbooks.refresh-token', $this->refreshToken);
        $ks->set('quickbooks.refresh-token-expires-in', $result['x_refresh_token_expires_in']);

        $this->dataServiceInit();
    }

    private function dataServiceCheckRetry($object){
        $resultObject = $this->dataService->add($object);
        $error = $this->dataService->getLastError();
        if ($error !== null){
            $statusCode = $error->getHttpStatusCode();
            echo "The Status code is: " . $statusCode . "\n";
            echo "The Helper message is: " . $error->getOAuthHelperError() . "\n";
            echo "The Response message is: " . $error->getResponseBody() . "\n";
            if (401 == $statusCode){
                // 401 == token expired, need to reconnect
                $this->reconnect();
                // !!! recursive call
                return $this->dataServiceCheckRetry($object);
            }
            else{
                throw new Exception($statusCode . ' ' . $error->getOAuthHelperError());
            }
        }
        return $resultObject;
    }

    public function createCustomer($data){
        return $this->dataServiceCheckRetry(Customer::create($data));
    }

    public function createInvoice($data){
        return $this->dataServiceCheckRetry(Invoice::create($data));
    }

    public function viewInvoices($pageNumber, $pageSize){
        $allInvoices = $this->dataService->FindAll('Invoice', $pageNumber, $pageSize);
        $error = $this->dataService->getLastError();
        if ($error != null) {
            echo "The Status code is: " . $error->getHttpStatusCode() . "\n";
            echo "The Helper message is: " . $error->getOAuthHelperError() . "\n";
            echo "The Response message is: " . $error->getResponseBody() . "\n";
            exit();
        }
        return $allInvoices;
    }
}