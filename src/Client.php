<?php declare(strict_types = 1);

namespace EcomailFlexibee;

use EcomailFlexibee\Exception\EcomailFlexibeeConnectionError;
use EcomailFlexibee\Exception\EcomailFlexibeeNoEvidenceResult;
use EcomailFlexibee\Exception\EcomailFlexibeeSaveFailed;
use EcomailFlexibee\Http\Method;
use EcomailFlexibee\Http\Response\FlexibeeBackupResponse;
use EcomailFlexibee\Http\Response\FlexibeePdfResponse;
use EcomailFlexibee\Http\Response\FlexibeeResponse;
use EcomailFlexibee\Http\Response\Response;
use EcomailFlexibee\Http\ResponseFactory;
use EcomailFlexibee\Http\ResponseHydrator;
use EcomailFlexibee\Http\UrlBuilder;
use EcomailFlexibee\Result\EvidenceResult;

class Client
{

    /**
     * @var \EcomailFlexibee\Http\UrlBuilder
     */
    private $queryBuilder;

    /**
     * @var \EcomailFlexibee\Config
     */
    private $config;

    /**
     * @var \EcomailFlexibee\Http\ResponseHydrator
     */
    private $responseHydrator;

    public function __construct(
        string $url,
        string $company,
        string $user,
        string $password,
        string $evidence,
        bool $selfSignedCertificate,
        ?string $authSessionId = null
    )
    {
        $this->config = new Config(
            $url,
            $company,
            $user,
            $password,
            $evidence,
            $selfSignedCertificate,
            $authSessionId
        );
        $this->queryBuilder = new UrlBuilder($this->config);
        $this->responseHydrator = new ResponseHydrator($this->config);
    }

    public function isAllowedChangesApi(): bool
    {
        return $this->makeRequest(
            Method::get(Method::GET),
            $this->queryBuilder->createChangesStatusUrl(),
            [],
            [],
            []
        )->isSuccess();
    }

    public function getChangesApiForEvidence(string $evidenceName): Response
    {
        return $this->makeRequest(
            Method::get(Method::GET),
            $this->queryBuilder->createChangesUrl(['evidence' => $evidenceName]),
            [],
            [],
            []
        );
    }

    public function getAllApiChanges(?string $fromVersion): Response
    {
        return $this->makeRequest(
            Method::get(Method::GET),
            $this->queryBuilder->createChangesUrl(['start' => $fromVersion]),
            [],
            [],
            []
        );
    }

    /**
     * @param array<mixed> $parameters
     * @return string
     */
    public function getLoginFormUrl(array $parameters): string
    {
        return $this->queryBuilder->createLoginFormUrl($parameters);
    }

    public function getAuthAndRefreshToken(): Response
    {
        return $this->makeRequest(
            Method::get(Method::POST),
            $this->queryBuilder->createAuthTokenUrl(),
            [],
            [
                'Content-Type: application/x-www-form-urlencoded',
            ],
            [
                'username' => $this->config->getUser(),
                'password' => $this->config->getPassword(),
            ]
        );
    }
    
    public function deleteById(int $id, bool $dryRun = false): Response
    {
        $uriParameters = $dryRun ? ['dry-run' => 'true'] : [];

        return $this->makeRequest(
            Method::get(Method::DELETE),
            $this->queryBuilder->createUri($id, $uriParameters),
            []
        );
    }
    
    public function deleteByCode(string $id, bool $dryRun = false): void
    {
        $uriParameters = $dryRun ? ['dry-run' => 'true'] : [];
        $this->makeRequest(
            Method::get(Method::DELETE),
            $this->queryBuilder->createUri(sprintf('code:%s', $id), $uriParameters),
            [],
            [],
            []
        );
    }

    /**
     * @param \EcomailFlexibee\Http\Response\Response $response
     * @return array<\EcomailFlexibee\Result\EvidenceResult>
     */
    private function convertResponseToEvidenceResults(Response $response): array
    {
        $data = $response->getData();

        if (!isset($data[$this->config->getEvidence()])) {
            return count($data) !== 0  ? [new EvidenceResult($data)] : [];
        }

        if (!isset($data[$this->config->getEvidence()])) {
            return [new EvidenceResult($data)];
        }

        return array_map(static function (array $data){
            return new EvidenceResult($data);
        }, $data[$this->config->getEvidence()]);
    }

    /**
     * @param int $id
     * @param array<mixed> $uriParameters
     * @return \EcomailFlexibee\Result\EvidenceResult
     * @throws \EcomailFlexibee\Exception\EcomailFlexibeeConnectionError
     * @throws \EcomailFlexibee\Exception\EcomailFlexibeeInvalidAuthorization
     * @throws \EcomailFlexibee\Exception\EcomailFlexibeeMethodNotAllowed
     * @throws \EcomailFlexibee\Exception\EcomailFlexibeeNotAcceptableRequest
     * @throws \EcomailFlexibee\Exception\EcomailFlexibeeRequestError
     */
    public function findById(int $id, array $uriParameters = []): EvidenceResult
    {
        try {
            return $this->getById($id, $uriParameters);
        } catch (EcomailFlexibeeNoEvidenceResult $exception) {
            return new EvidenceResult([]);
        }
    }

    /**
     * @param string $code
     * @param array<mixed> $uriParameters
     * @return \EcomailFlexibee\Result\EvidenceResult
     * @throws \EcomailFlexibee\Exception\EcomailFlexibeeConnectionError
     * @throws \EcomailFlexibee\Exception\EcomailFlexibeeInvalidAuthorization
     * @throws \EcomailFlexibee\Exception\EcomailFlexibeeInvalidRequestParameter
     * @throws \EcomailFlexibee\Exception\EcomailFlexibeeMethodNotAllowed
     * @throws \EcomailFlexibee\Exception\EcomailFlexibeeNoEvidenceResult
     * @throws \EcomailFlexibee\Exception\EcomailFlexibeeNotAcceptableRequest
     * @throws \EcomailFlexibee\Exception\EcomailFlexibeeRequestError
     */
    public function getByCode(string $code, array $uriParameters = []): EvidenceResult
    {
        return $this->responseHydrator->convertResponseToEvidenceResult(
            $this->makeRequest(
                Method::get(Method::GET),
                $this->queryBuilder->createUriByCodeOnly($code, $uriParameters),
                []
            ) ,
            true
        );
    }

    /**
     * @param int $id
     * @param array<string> $uriParameters
     * @return \EcomailFlexibee\Result\EvidenceResult
     * @throws \EcomailFlexibee\Exception\EcomailFlexibeeConnectionError
     * @throws \EcomailFlexibee\Exception\EcomailFlexibeeInvalidAuthorization
     * @throws \EcomailFlexibee\Exception\EcomailFlexibeeMethodNotAllowed
     * @throws \EcomailFlexibee\Exception\EcomailFlexibeeNoEvidenceResult
     * @throws \EcomailFlexibee\Exception\EcomailFlexibeeNotAcceptableRequest
     * @throws \EcomailFlexibee\Exception\EcomailFlexibeeRequestError
     */
    public function getById(int $id, array $uriParameters = []): EvidenceResult
    {
        return $this->responseHydrator->convertResponseToEvidenceResult(
            $this->makeRequest(
                Method::get(Method::GET),
                $this->queryBuilder->createUri($id, $uriParameters),
                []
            ),
            true
        );
    }

    /**
     * @param string $code
     * @param array<mixed> $uriParameters
     * @return \EcomailFlexibee\Result\EvidenceResult
     * @throws \EcomailFlexibee\Exception\EcomailFlexibeeConnectionError
     * @throws \EcomailFlexibee\Exception\EcomailFlexibeeInvalidAuthorization
     * @throws \EcomailFlexibee\Exception\EcomailFlexibeeInvalidRequestParameter
     * @throws \EcomailFlexibee\Exception\EcomailFlexibeeMethodNotAllowed
     * @throws \EcomailFlexibee\Exception\EcomailFlexibeeNotAcceptableRequest
     * @throws \EcomailFlexibee\Exception\EcomailFlexibeeRequestError
     */
    public function findByCode(string $code, array $uriParameters = []): EvidenceResult
    {
        try {
            return $this->getByCode($code, $uriParameters);
        } catch (EcomailFlexibeeNoEvidenceResult $exception) {
            return new EvidenceResult([]);
        }
    }

    /**
     * @param array<mixed> $evidenceData
     * @param int|null $id
     * @param bool $dryRun
     * @param array<mixed> $uriParameters
     * @return \EcomailFlexibee\Http\Response\FlexibeeResponse
     * @throws \EcomailFlexibee\Exception\EcomailFlexibeeConnectionError
     * @throws \EcomailFlexibee\Exception\EcomailFlexibeeInvalidAuthorization
     * @throws \EcomailFlexibee\Exception\EcomailFlexibeeMethodNotAllowed
     * @throws \EcomailFlexibee\Exception\EcomailFlexibeeNotAcceptableRequest
     * @throws \EcomailFlexibee\Exception\EcomailFlexibeeRequestError
     * @throws \EcomailFlexibee\Exception\EcomailFlexibeeSaveFailed
     */
    public function save(array $evidenceData, ?int $id, bool $dryRun = false, array $uriParameters = []): FlexibeeResponse
    {
        if ($id !== null) {
            $evidenceData['id'] = $id;
        }

        $postData = [];
        $postData[$this->config->getEvidence()] = $evidenceData;
        $uriParameters = $dryRun ? array_merge($uriParameters, ['dry-run' => 'true']) : $uriParameters;
        /** @var \EcomailFlexibee\Result\EvidenceResult $response */
        $response = $this->callRequest(Method::get(Method::PUT), null, $uriParameters, $postData, [])[0];
        $data = $response->getData();

        if (isset($data['created']) && (int) $data['created'] === 0 && isset($data['updated']) && (int) $data['updated'] === 0) {
            $errorMessage = sprintf('(%d) %s', $data['status_code'], $data['message']);

            throw new EcomailFlexibeeSaveFailed($errorMessage);
        }

        if (isset($data['success']) && $data['success'] !== 'true' && isset($data['message'])) {
            throw new EcomailFlexibeeSaveFailed($data['message']);
        }

        return new FlexibeeResponse(
            200,
            null,
            true,
            null,
            count($data),
            null,
            $response->getData(),
            []
        );
    }

    public function createDeductionFromProforma(int $proformaInvoiceId, int $issuedInvoiceId, float $price): void
    {
        $issuedInvoiceData = $this->getById($issuedInvoiceId)->getData()[0];
        $relationData = [
            'id' => $issuedInvoiceId,
            'typDokl' => $issuedInvoiceData['typDokl'],
            'odpocty-zaloh' => [
                'odpocet' => [
                    'castkaMen' => $price,
                    'doklad' => $proformaInvoiceId,
                ],
            ],
        ];

        $this->save($relationData, null);
    }

    public function getUserRelations(int $objectId): EvidenceResult
    {
        $data = $this->getById($objectId, ['relations' => 'uzivatelske-vazby'])->getData()[0]['uzivatelske-vazby'];

        return new EvidenceResult($data);
    }

    public function addUserRelation(int $objectAId, int $objectBId, float $price, int $relationTypeId, ?string $description = null): void
    {
        $objectBData = $this->getById($objectBId, [])->getData()[0];
        $relationData = [
            'id' => $objectAId,
            'uzivatelske-vazby' => [
                'uzivatelska-vazba' => [
                    'vazbaTyp' => $relationTypeId,
                    'cena' => $price,
                    'popis' => $description,
                    'evidenceType' => $this->config->getEvidence(),
                    'object' => sprintf('code:%s', $objectBData['kod']),
                ],
            ],
        ];

        $this->save($relationData, $objectAId);
    }

    /**
     * @return array<mixed>
     * @throws \EcomailFlexibee\Exception\EcomailFlexibeeConnectionError
     * @throws \EcomailFlexibee\Exception\EcomailFlexibeeInvalidAuthorization
     * @throws \EcomailFlexibee\Exception\EcomailFlexibeeMethodNotAllowed
     * @throws \EcomailFlexibee\Exception\EcomailFlexibeeNotAcceptableRequest
     * @throws \EcomailFlexibee\Exception\EcomailFlexibeeRequestError
     */
    public function allInEvidence(): array
    {
        $response = $this->makeRequest(
            Method::get(Method::GET),
            $this->queryBuilder->createUriByEvidenceOnly(['limit' => 0]),
            [],
            [],
            []
        );

        return $this->responseHydrator->convertResponseToEvidenceResults($response);
    }

    public function countInEvidence(): int
    {
        $response = $this->makeRequest(
            Method::get(Method::GET),
            $this->queryBuilder->createUriByEvidenceOnly(['add-row-count' => 'true']),
            [],
            [],
            []
        );

        /** @var int $result */
        $result = $response->getRowCount() ?? 0;

        return $result;
    }

    /**
     * @param int $start
     * @param int $limit
     * @return array<\EcomailFlexibee\Result\EvidenceResult>
     * @throws \EcomailFlexibee\Exception\EcomailFlexibeeConnectionError
     * @throws \EcomailFlexibee\Exception\EcomailFlexibeeInvalidAuthorization
     * @throws \EcomailFlexibee\Exception\EcomailFlexibeeMethodNotAllowed
     * @throws \EcomailFlexibee\Exception\EcomailFlexibeeNotAcceptableRequest
     * @throws \EcomailFlexibee\Exception\EcomailFlexibeeRequestError
     */
    public function chunkInEvidence(int $start, int $limit): array
    {
        $response = $this->makeRequest(
            Method::get(Method::GET),
            $this->queryBuilder->createUriByEvidenceOnly(['limit' => $limit, 'start' => $start]),
            [],
            [],
            []
        );

        return $this->responseHydrator->convertResponseToEvidenceResults($response);
    }

    /**
     * @param string $query
     * @param array<string> $uriParameters
     * @return array<\EcomailFlexibee\Result\EvidenceResult>
     * @throws \EcomailFlexibee\Exception\EcomailFlexibeeConnectionError
     * @throws \EcomailFlexibee\Exception\EcomailFlexibeeInvalidAuthorization
     * @throws \EcomailFlexibee\Exception\EcomailFlexibeeMethodNotAllowed
     * @throws \EcomailFlexibee\Exception\EcomailFlexibeeNotAcceptableRequest
     * @throws \EcomailFlexibee\Exception\EcomailFlexibeeRequestError
     */
    public function searchInEvidence(string $query, array $uriParameters): array
    {
        $response = $this->makeRequest(
            Method::get(Method::GET),
            $this->queryBuilder->createFilterQuery($query, $uriParameters),
            [],
            [],
            []
        );

        return $this->responseHydrator->convertResponseToEvidenceResults($response);
    }

    /**
     * @param \EcomailFlexibee\Http\Method $httpMethod
     * @param mixed $queryFilterOrId
     * @param array<mixed> $uriParameters
     * @param array<mixed> $postFields
     * @param array<string> $headers
     * @return array<\EcomailFlexibee\Result\EvidenceResult>
     * @throws \EcomailFlexibee\Exception\EcomailFlexibeeConnectionError
     * @throws \EcomailFlexibee\Exception\EcomailFlexibeeForbidden
     * @throws \EcomailFlexibee\Exception\EcomailFlexibeeInvalidAuthorization
     * @throws \EcomailFlexibee\Exception\EcomailFlexibeeMethodNotAllowed
     * @throws \EcomailFlexibee\Exception\EcomailFlexibeeNotAcceptableRequest
     * @throws \EcomailFlexibee\Exception\EcomailFlexibeeRequestError
     */
    public function callRequest(
        Method $httpMethod,
        $queryFilterOrId,
        array $uriParameters,
        array $postFields,
        array $headers
    ): array
    {
        $response = $this->makeRequest(
            $httpMethod,
            $this->queryBuilder->createUri($queryFilterOrId, $uriParameters),
            $postFields,
            $headers
        );

        return $this->responseHydrator->convertResponseToEvidenceResults($response);
    }

    public function backup(): Response
    {
        return $this->makeRequest(
            Method::get(Method::GET),
            $this->queryBuilder->createBackupUrl(),
            []
        );
    }

    public function restore(string $companyName, string $data): Response
    {
        return $this->makeRequest(
            Method::get(Method::GET),
            $this->queryBuilder->createRestoreUrl($companyName),
            [$data],
            [],
            [],
            true
        );
    }

    /**
     * @param int $id
     * @param array<mixed> $uriParameters
     * @return \EcomailFlexibee\Http\Response\Response
     * @throws \EcomailFlexibee\Exception\EcomailFlexibeeConnectionError
     * @throws \EcomailFlexibee\Exception\EcomailFlexibeeForbidden
     * @throws \EcomailFlexibee\Exception\EcomailFlexibeeInvalidAuthorization
     * @throws \EcomailFlexibee\Exception\EcomailFlexibeeMethodNotAllowed
     * @throws \EcomailFlexibee\Exception\EcomailFlexibeeNotAcceptableRequest
     * @throws \EcomailFlexibee\Exception\EcomailFlexibeeRequestError
     */
    public function getPdfById(int $id, array $uriParameters): Response
    {
        return $this->makeRequest(
            Method::get(Method::GET),
            $this->queryBuilder->createPdfUrl($id, $uriParameters),
            []
        );
    }

    /**
     * @param \EcomailFlexibee\Http\Method $httpMethod
     * @param string $url
     * @param array<mixed> $postFields
     * @param array<string> $headers
     * @param array<mixed> $queryParameters
     * @param bool $rawPostFields
     * @return \EcomailFlexibee\Http\Response\Response|\EcomailFlexibee\Http\Response\FlexibeePdfResponse
     * @throws \EcomailFlexibee\Exception\EcomailFlexibeeConnectionError
     * @throws \EcomailFlexibee\Exception\EcomailFlexibeeForbidden
     * @throws \EcomailFlexibee\Exception\EcomailFlexibeeInvalidAuthorization
     * @throws \EcomailFlexibee\Exception\EcomailFlexibeeMethodNotAllowed
     * @throws \EcomailFlexibee\Exception\EcomailFlexibeeNotAcceptableRequest
     * @throws \EcomailFlexibee\Exception\EcomailFlexibeeRequestError
     */
    private function makeRequest(Method $httpMethod, string $url, array $postFields = [], array $headers = [], array $queryParameters = [], bool $rawPostFields = false)
    {
        $url = urldecode($url);

        /** @var resource $ch */
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, TRUE);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);

        if ($this->config->getAuthSessionId() !== null) {
            curl_setopt($ch, CURLOPT_HTTPAUTH, FALSE);
            $headers[] = sprintf('X-authSessionId: %s', $this->config->getAuthSessionId());
        } else {
            curl_setopt($ch, CURLOPT_HTTPAUTH, TRUE);
            curl_setopt($ch, CURLOPT_USERPWD, sprintf('%s:%s', $this->config->getUser(), $this->config->getPassword()));
        }

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $httpMethod->getValue());
        curl_setopt($ch, CURLOPT_USERAGENT, 'Ecomail.cz Flexibee client (https://github.com/Ecomailcz/flexibee-client)');

        if ($this->config->isSelfSignedCertificate() || $this->config->getAuthSessionId() !== null) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, FALSE);
        }

        $postData = [];

        if (count($postFields) !== 0) {
            if (!$rawPostFields) {
                $postData['winstrom'] = $postFields;
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
            } else {
                curl_setopt($ch, CURLOPT_POSTFIELDS, implode("\n", $postFields));
            }
        }

        if (count($queryParameters) !== 0) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($queryParameters));
        }

        if (count($headers) !== 0) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        $output = curl_exec($ch);

        if (curl_errno($ch) !== CURLE_OK || !is_string($output)) {
            throw new EcomailFlexibeeConnectionError(sprintf('cURL error (%s): %s', curl_errno($ch), curl_error($ch)));
        }

        // PDF content
        if (mb_strpos($url, '.pdf') !== false) {
            return new FlexibeePdfResponse($output);
        }

        // Backup content
        if ($httpMethod->equalsValue(Method::GET) && mb_stripos($url, '/backup') !== false) {
            return new FlexibeeBackupResponse($output);
        }

        return ResponseFactory::createFromOutput($output, curl_getinfo($ch, CURLINFO_HTTP_CODE));
    }

}
