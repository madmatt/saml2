<?php
/**
 * Implementation of the SAML 2.0 SOAP binding.
 *
 * @author Shoaib Ali
 * @package simpleSAMLphp
 * @version $Id$
 */
class SAML2_SOAPClient {

	const START_SOAP_ENVELOPE = '<soap-env:Envelope xmlns:soap-env="http://schemas.xmlsoap.org/soap/envelope/"><soap-env:Header/><soap-env:Body>';
	const END_SOAP_ENVELOPE = '</soap-env:Body></soap-env:Envelope>';

	/**
	 * This function sends the SOAP message to the service location and returns SOAP response
	 *
	 * @param SAML2_Message $m  The request that should be sent.
	 * @param SimpleSAML_Configuration $srcMetadata  The metadata of the issuer of the message.
	 * @param SimpleSAML_Configuration $dstMetadata  The metadata of the destination of the message.
	 * @return SAML2_Message  The response we received.
	 */
	public function send(SAML2_Message $msg, SimpleSAML_Configuration $srcMetadata, SimpleSAML_Configuration $dstMetadata = NULL) {

		$issuer = $msg->getIssuer();

		$options = array(
			'uri' => $issuer,
			'location' => $msg->getDestination(),
		);

		// Determine if we are going to do a MutualSSL connection between the IdP and SP  - Shoaib
		if ($srcMetadata->hasValue('saml.SOAPClient.certificate')) {
			$options['local_cert'] = SimpleSAML_Utilities::resolveCert($srcMetadata->getString('saml.SOAPClient.certificate'));
			if ($srcMetadata->hasValue('saml.SOAPClient.privatekey_pass')) {
				$options['passphrase'] = $srcMetadata->getString('saml.SOAPClient.privatekey_pass');
			}
		} else {
			/* Use the SP certificate and privatekey if it is configured. */
			$privateKey = SimpleSAML_Utilities::loadPrivateKey($srcMetadata);
			$publicKey = SimpleSAML_Utilities::loadPublicKey($srcMetadata);
			if ($privateKey !== NULL && $publicKey !== NULL && isset($publicKey['PEM'])) {
				$keyCertData = $privateKey['PEM'] . $publicKey['PEM'];
				$file = SimpleSAML_Utilities::getTempDir() . '/' . sha1($keyCertData) . '.pem';
				if (!file_exists($file)) {
					SimpleSAML_Utilities::writeFile($file, $keyCertData);
				}
				$options['local_cert'] = $file;
				if (isset($privateKey['password'])) {
					$options['passphrase'] = $privateKey['password'];
				}
			}
		}

		// do peer certificate verification
		if ($dstMetadata !== NULL) {
			$peerPublicKey = SimpleSAML_Utilities::loadPublicKey($dstMetadata);
			if ($peerPublicKey !== NULL) {
				$certData = $peerPublicKey['PEM'];
				$peerCertFile = SimpleSAML_Utilities::getTempDir() . '/' . sha1($certData) . '.pem';
				if (!file_exists($peerCertFile)) {
					SimpleSAML_Utilities::writeFile($peerCertFile, $certData);
				}
				// create ssl context
				$ctxOpts = array(
					'ssl' => array(
						'verify_peer' => TRUE,
						'verify_depth' => 1,
						'cafile' => $peerCertFile
						));
				if (isset($options['local_cert'])) {
					$ctxOpts['ssl']['local_cert'] = $options['local_cert'];
					unset($options['local_cert']);
				}
				if (isset($options['passhprase'])) {
					$ctxOpts['ssl']['passphrase'] = $options['passphrase'];
					unset($options['passphrase']);
				}
				$context = stream_context_create($ctxOpts);
				if ($context === NULL) {
					throw new Exception('Unable to create SSL stream context');
				}
				$options['stream_context'] = $context;
			} else {
				throw new Exception('IdP metadata was supplied, but no certData present');
			}
		}

		$x = new SoapClient(NULL, $options);

		// Add soap-envelopes
		$request = $msg->toSignedXML();
		$request = self::START_SOAP_ENVELOPE . $request->ownerDocument->saveXML($request) . self::END_SOAP_ENVELOPE;

		$action = 'http://www.oasis-open.org/committees/security';
		$version = '1.1';
		$destination = $msg->getDestination();


		/* Perform SOAP Request over HTTP */
		$soapresponsexml = $x->__doRequest($request, $destination, $action, $version);
		if ($soapresponsexml === NULL || $soapresponsexml === "") {
			throw new Exception('Empty SOAP response, check peer certificate.');
		}

		// Convert to SAML2_Message (DOMElement)
		$dom = new DOMDocument();
		if (!$dom->loadXML($soapresponsexml)) {
			throw new Exception('Not a SOAP response.');
		}

		$soapfault = $this->getSOAPFault($dom);
		if (isset($soapfault)) {
			throw new Exception($soapfault);
		}
		//Extract the message from the response
		$xml = $dom->firstChild;    /* Soap Envelope */
		$samlresponse = SAML2_Utils::xpQuery($dom->firstChild, '/soap-env:Envelope/soap-env:Body/*[1]');
		$samlresponse = SAML2_Message::fromXML($samlresponse[0]);


		SimpleSAML_Logger::debug("Valid ArtifactResponse received from IdP");

		return $samlresponse;

	}


	/*
	 * Extracts the SOAP Fault from SOAP message
	 * @param $soapmessage Soap response needs to be type DOMDocument
	 * @return $soapfaultstring string|NULL
	 */
	private function getSOAPFault($soapmessage) {

		$soapfault = SAML2_Utils::xpQuery($soapmessage->firstChild, '/soap-env:Envelope/soap-env:Body/soap-env:Fault');

		if (empty($soapfault)) {
			/* No fault. */
			return NULL;
		}
		$soapfaultelement = $soapfault[0];
		$soapfaultstring = "Unknown fault string found"; // There is a fault element but we havn't found out what the fault string is
		// find out the fault string
		$faultstringelement =   SAML2_Utils::xpQuery($soapfaultelement, './soap-env:faultstring') ;
		if (!empty($faultstringelement)) {
			return $faultstringelement[0]->textContent;
		}
		return $soapfaultstring;
	}

}