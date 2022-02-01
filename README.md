Prezent SoapClient
==================

This is a fork of [prezent/soap-client](https://github.com/Prezent/soap-client) package.


Example usage
-------------

```php
use Prezent\Soap\Client\SoapClient;
use Prezent\Soap\Client\Extension\WSAddressing;

$client = new SoapClient('http://example.org/wsa-server.wsdl', [
    'event_subscribers' => [
        new WSAddressing('http://example.org/return-address'),
    ],
]);

$client->someMethod('arg');
```


Documentation
-------------

The complete documentation can be found in the [doc directory](doc/index.md).
