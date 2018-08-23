Yii2 Extension for Quickbooks
=============================
quickbooks online oauth2 and API wrapper for Yii2

Installation
------------

The preferred way to install this extension is through [composer](http://getcomposer.org/download/).

Either run

```
php composer.phar require --prefer-dist omcrn/yii2-quickbooks "*"
```

or add

```
"omcrn/yii2-quickbooks": "*"
```

to the require section of your `composer.json` file.


Usage
-----

Save the following values in .env file:

```php
QB_BASE_URL                 = https://quickbooks.api.intuit.com/
QB_DISCOVERY_DOCUMENT       = https://developer.intuit.com/.well-known/openid_sandbox_configuration/
QB_CLIENT_ID                = ****************************************
QB_CLIENT_SECRET            = ****************************************
QB_OAUTH_SCOPE              = com.intuit.quickbooks.accounting
QB_REALM_ID                 = ****************************************
```
You must have ```Yii::$app->keyStorage``` configured, because the extension
uses KeyStorage to save quickbooks authorization tokens.
Also you need to save your redirect-url in KeyStorage as a key ```quickbooks.redirect-url```

Now you can initialize the extensions like this:
```php
$qb = new Quickbooks([
   'authMode' => "oauth2",
   'clientId' => getenv("QB_CLIENT_ID"),
   'clientSecret' => getenv("QB_CLIENT_SECRET"),
   'baseUrl' => getenv("QB_BASE_URL"),
   'realmId' => getenv("QB_REALM_ID"),
   'discoveryDocumentUrl' => getenv("QB_DISCOVERY_DOCUMENT"),
   'oauthScope' => getenv("QB_OAUTH_SCOPE")
]);
```

Implement the 'Connect to Quickbooks' button as in Intuit's Docs:
```php
https://developer.intuit.com/docs/00_quickbooks_online/2_build/10_authentication_and_authorization/40_oauth_1.0a/widgets
```
Basically you need to include their .js file and run the following script:
```php
intuit.ipp.anywhere.setup({
    grantUrl: '/redirect-url', //the one you saved in KeyStorage
    datasources: {
        quickbooks : true,
        payments : true
    },
    paymentOptions:{
        intuitReferred : true
    }
});
```
You need to implement your redirect-url handler action,
initialize Quickbooks extension there and call the connect method:
```php
$qb->connect();
```
Now you need to click that button, to obtain auth tokens. Tokens are automatically
refreshed each request, so this is one time operation.

When the configuration is done, you can create your custom action,
initialize the extension there and call required methods, e.g. for
creating a customer you can do the following in your action:
```php
$newCustomer = $qb->createCustomer([
   "BillAddr" => [
       "Line1" => $address->address,
       "Line2" => $address->address2,
       "City" => $address->town,
       "Country" => $address->country->name,
       "CountrySubDivisionCode" => $address->country->iso_code_2,
       "PostalCode" => $address->postcode
   ],
   "Notes" => $notes,
   "Title" => $title,
   "GivenName" => $name,
   "MiddleName" => "",
   "FamilyName" => $surname,
   "Suffix" => $suffix,
   "FullyQualifiedName" => $this->name . " " . $this->surname,
   "CompanyName" => $companyName,
   "DisplayName" => $displayName,
   "PrimaryPhone" => [
       "FreeFormNumber" => $mobile
   ],
   "PrimaryEmailAddr" => [
       "Address" => $email
   ]
]);
```
