<?php

namespace Arbory\Soap\Client\Tests\Extension;

use PHPUnit\Framework\TestCase;
use Arbory\Soap\Client\SoapClient;
use Arbory\Soap\Client\Event\CallEvent;
use Arbory\Soap\Client\Event\FaultEvent;
use Arbory\Soap\Client\Event\FinishEvent;
use Arbory\Soap\Client\Event\RequestEvent;
use Arbory\Soap\Client\Event\ResponseEvent;
use Arbory\Soap\Client\Event\WsdlRequestEvent;
use Arbory\Soap\Client\Event\WsdlResponseEvent;
use Arbory\Soap\Client\Events;
use Arbory\Soap\Client\Extension\WSAddressing;

class WSAddressingTest extends TestCase
{
    public function testDetectWsaNotEnabled()
    {
        $wsdl = new \DOMDocument();
        $wsdl->loadXML(file_get_contents(__DIR__ . '/../Fixtures/hello-world.wsdl'));

        $event = new WsdlResponseEvent($wsdl);

        $wsaExtension = new WSAddressing();
        $wsaExtension->onWsdlResponse($event);

        $this->assertFalse($wsaExtension->isEnabled());
    }

    public function testDetectWsaEnabled()
    {
        $wsdl = new \DOMDocument();
        $wsdl->loadXML(file_get_contents(__DIR__ . '/../Fixtures/hello-wsa.wsdl'));

        $event = new WsdlResponseEvent($wsdl);

        $wsaExtension = new WSAddressing();
        $wsaExtension->onWsdlResponse($event);

        $this->assertTrue($wsaExtension->isEnabled());
    }

    public function testWsaRequest()
    {
        $wsaExtension = new WSAddressing();
        $wsaExtension->setFrom(true);
        $request = null;

        $client = new SoapClient(__DIR__ . '/../Fixtures/hello-wsa.wsdl', [
            'event_listeners' => [
                [Events::REQUEST, function (RequestEvent $event) use (&$request) {
                    $request = $event->getRequest();
                }],
                [Events::REQUEST, [$this, 'handleRequest'], -10],
            ],
            'event_subscribers' => [$wsaExtension],
        ]);

        $client->sayHello('World');

        $this->assertInstanceOf(\DOMDocument::class, $request);

        $xpath = new \DOMXPath($request);
        $xpath->registerNamespace('wsa', WSAddressing::NS_WSA);

        $this->assertEquals(1, $xpath->query('//wsa:MessageID')->length);
        $this->assertEquals(1, $xpath->query('//wsa:Action')->length);
        $this->assertEquals(1, $xpath->query('//wsa:To')->length);
        $this->assertEquals(1, $xpath->query('//wsa:ReplyTo')->length);
        $this->assertEquals(1, $xpath->query('//wsa:From')->length);
    }

    /**
     * Event listener to generate a "Hello, World!" response
     *
     * @param RequestEvent $event
     * @return void
     */
    public function handleRequest(RequestEvent $event)
    {
        $xml = <<<XML
<SOAP-ENV:Envelope
    xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/"
    xmlns:ns1="urn:examples:helloservice"
    xmlns:xsd="http://www.w3.org/2001/XMLSchema"
    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
    xmlns:SOAP-ENC="http://schemas.xmlsoap.org/soap/encoding/"
    xmlns:wsa="http://schemas.xmlsoap.org/ws/2004/08/addressing"
    SOAP-ENV:encodingStyle="http://schemas.xmlsoap.org/soap/encoding/">
    <SOAP-ENV:Header>
        <wsa:MessageID>uuid:c2545fb2-06fb-828c-97db-ac6fb9f6dd6e</wsa:MessageID>
        <wsa:To>http://schemas.xmlsoap.org/ws/2004/08/addressing/role/anonymous</wsa:To>
        <wsa:Action>sayHello</wsa:Action>
    </SOAP-ENV:Header>
    <SOAP-ENV:Body>
        <ns1:sayHelloResponse>
            <greeting xsi:type="xsd:string">Hello, World!</greeting>
        </ns1:sayHelloResponse>
    </SOAP-ENV:Body>
</SOAP-ENV:Envelope>
XML;

        $event->setRequestHeaders('X-Header: request');
        $event->setResponse($xml);
        $event->setResponseHeaders('X-Header: response');
        $event->stopPropagation();
    }
}
