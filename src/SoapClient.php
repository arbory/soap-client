<?php

namespace Arbory\Soap\Client;

use Arbory\Soap\Client\Event\CallEvent;
use Arbory\Soap\Client\Event\FaultEvent;
use Arbory\Soap\Client\Event\FinishEvent;
use Arbory\Soap\Client\Event\RequestEvent;
use Arbory\Soap\Client\Event\ResponseEvent;
use Arbory\Soap\Client\Event\WsdlRequestEvent;
use Arbory\Soap\Client\Event\WsdlResponseEvent;
use SoapClient as BaseSoapClient;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Extendable SOAP client
 *
 * @author Sander Marechal
 */
class SoapClient extends BaseSoapClient
{
    /**
     * @var EventDispatcherInterface
     */
    private $eventDispatcher;

    /**
     * @var bool Toggle tracing. This cannot be named `trace` or it will conflict with an internal SoapClient variable
     */
    private $tracing = false;

    const NS_SOAP_ENVELOPE = [
        SOAP_1_1 => 'http://schemas.xmlsoap.org/soap/envelope/',
        SOAP_1_2 => 'http://www.w3.org/2003/05/soap-envelope',
    ];

    /**
     * {@inheritDoc}
     */
    public function __construct($wsdl, array $options = [])
    {
        // Setup stream context
        $this->streamContext = isset($options['stream_context'])
            ? $options['stream_context']
            : stream_context_create();

        // Check for tracing
        if (isset($options['trace'])) {
            $this->tracing = $options['trace'];
        }

        // Set up event dispatcher
        if (isset($options['event_dispatcher'])) {
            if (!($options['event_dispatcher'] instanceof EventDispatcherInterface)) {
                throw new \RuntimeException(
                    "The 'event_dispatcher' option must be a " . EventDispatcherInterface::class
                );
            }

            $this->eventDispatcher = $options['event_dispatcher'];
            unset($options['event_dispatcher']);
        } else {
            $this->eventDispatcher = new EventDispatcher();
        }

        // Attach listeners
        if (isset($options['event_listeners'])) {
            foreach ($options['event_listeners'] as $listener) {
                call_user_func_array([$this, '__addListener'], $listener);
            }

            unset($options['event_listeners']);
        }

        // Attach subscribers
        if (isset($options['event_subscribers'])) {
            foreach ($options['event_subscribers'] as $subscriber) {
                $this->__addSubscriber($subscriber);
            }

            unset($options['event_subscribers']);
        }

        // Fallback listener for loading WSDL files
        $this->__addListener(Events::WSDL_REQUEST, function (WsdlRequestEvent $event) {
            $event->setWsdl(file_get_contents($event->getUri(), false, $this->streamContext));
            $event->stopPropagation();
        }, -999);

        // Fallback listener for making SOAP requests
        $this->__addListener(Events::REQUEST, function (RequestEvent $event) {
            $response = parent::__doRequest(
                $event->getRequest()->saveXML(),
                $event->getLocation(),
                $event->getAction(),
                $event->getVersion(),
                (int) $event->isOneWay()
            );

            $event->setResponse($response);
            $event->stopPropagation();
        }, -999);

        // Load WSDL using a data:// URI. This allows us to load the WSDL by any transport
        // instead of always using the built-in method. It also allows custom WSDL parsing
        parent::__construct('data://text/xml;base64,' . base64_encode($this->getWsdl($wsdl)), $options);
    }

    /**
     * Add an event listener
     *
     * @param string $eventName
     * @param callable $listener
     * @param int $priority
     * @return void
     */
    public function __addListener($eventName, $listener, $priority = 0)
    {
        $this->eventDispatcher->addListener($eventName, $listener, $priority);
    }

    /**
     * Add an event subscriber
     *
     * @param EventSubscriberInterface $subscriber
     * @return void
     */
    public function __addSubscriber(EventSubscriberInterface $subscriber)
    {
        $this->eventDispatcher->addSubscriber($subscriber);
    }

    /**
     * {@inheritDoc}
     */
    public function __call($method, $args): mixed
    {
        $event = new CallEvent($method, $args);
        $this->eventDispatcher->dispatch($event, Events::CALL);

        try {
            $response = parent::__call($event->getMethod(), $event->getArguments());
        } catch (\SoapFault $fault) {
            return $this->handleFault($fault);
        }

        return $this->eventDispatcher->dispatch(new FinishEvent($response), Events::FINISH)->getResponse();
    }

    /**
     * {@inheritDoc}
     */
    public function __soapCall($method, $args, $options = [], $inputHeaders = [], &$outputHeaders = []): mixed
    {
        $event = new CallEvent($method, $args);
        $this->eventDispatcher->dispatch($event, Events::CALL);

        try {
            $response = parent::__soapCall(
                $event->getMethod(),
                $event->getArguments(),
                $options,
                $inputHeaders,
                $outputHeaders
            );
        } catch (\SoapFault $fault) {
            return $this->handleFault($fault);
        }

        return $this->eventDispatcher->dispatch(new FinishEvent($response), Events::FINISH)->getResponse();
    }

    /**
     * {@inheritDoc}
     */
    public function __doRequest($request, $location, $action, $version, $oneWay = 0): ?string
    {
        $dom = new \DOMDocument();
        $dom->loadXML($request);

        try {
            $requestEvent = new RequestEvent($dom, $location, $action, $version, $oneWay === 1);
            $this->eventDispatcher->dispatch($requestEvent, Events::REQUEST);
        } catch (\Exception $e) {
            $this->__soap_fault = new \SoapFault(
                'Client',
                'Error during ' . Events::REQUEST . ' event: ' . $e->getMessage(),
                get_class($e)
            );

            return null;
        }

        if ($this->tracing) {
            $this->__last_request = $requestEvent->getRequest()->saveXML();
            $this->__last_request_headers = $requestEvent->getRequestHeaders();
        }

        if (!$requestEvent->getResponse()) {
            $this->__soap_fault = new \SoapFault('Client', 'No response could be generated');
            return null;
        }

        try {
            $dom = new \DOMDocument();
            $loaded = @$dom->loadXML($requestEvent->getResponse()); // Mask error, check return value instead

            if (!$loaded || $dom->getElementsByTagNameNS(self::NS_SOAP_ENVELOPE[$version], 'Envelope')->length == 0) {
                $this->__last_response = $requestEvent->getResponse();
                $this->__last_response_headers = $requestEvent->getResponseHeaders();

                throw new \RuntimeException('Response is not a SOAP response');
            }

            $responseEvent = new ResponseEvent($dom);
            $this->eventDispatcher->dispatch($responseEvent, Events::RESPONSE);
        } catch (\Exception $e) {
            $this->__soap_fault = new \SoapFault(
                'Client',
                'Error during ' . Events::RESPONSE . ' event: ' . $e->getMessage(),
                get_class($e)
            );

            return null;
        }

        if ($this->tracing) {
            $this->__last_response = $responseEvent->getResponse()->saveXML();
            $this->__last_response_headers = $requestEvent->getResponseHeaders();
        }

        return $responseEvent->getResponse()->saveXML();
    }

    /**
     * Get the WSDL file
     *
     * @param mixed $uri
     * @return void
     */
    private function getWsdl($uri)
    {
        $wsdl = $this->eventDispatcher->dispatch(new WsdlRequestEvent($uri), Events::WSDL_REQUEST)->getWsdl();

        if (!$wsdl) {
            throw new \RuntimeException(sprintf('Could not load WSDL from "%s"', $uri));
        }

        $dom = new \DomDocument();
        $dom->loadXML($wsdl);

        $event = new WsdlResponseEvent($dom);
        $this->eventDispatcher->dispatch($event, Events::WSDL_RESPONSE);

        return $event->getWsdl()->saveXML();
    }

    /**
     * Handle a SoapFault
     *
     * If event propagation is not stopped, the fault will be re-thrown
     *
     * @param \SoapFault $fault
     * @return mixed
     */
    private function handleFault(\SoapFault $fault)
    {
        $event = new FaultEvent(
            $fault,
            $this->__getLastRequest(),
            $this->__getLastRequestHeaders(),
            $this->__getLastResponse(),
            $this->__getLastResponseHeaders()
        );

        if (!$this->eventDispatcher->dispatch($event, Events::FAULT)->isPropagationStopped()) {
            throw $fault;
        }

        return $event->getResponse();
    }
}
