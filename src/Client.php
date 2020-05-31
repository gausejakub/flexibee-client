<?php declare(strict_types = 1);

namespace EcomailFlexibee;

use EcomailFlexibee\Exception\EcomailFlexibeeNoEvidenceResult;
use EcomailFlexibee\Exception\EcomailFlexibeeSaveFailed;
use EcomailFlexibee\Http\HttpClient;
use EcomailFlexibee\Http\Method;
use EcomailFlexibee\Http\Response\FlexibeeResponse;
use EcomailFlexibee\Http\Response\Response;
use EcomailFlexibee\Http\ResponseHydrator;
use EcomailFlexibee\Http\UrlBuilder;
use EcomailFlexibee\Result\EvidenceResult;

class Client
{

    protected \EcomailFlexibee\Http\UrlBuilder $queryBuilder;
    protected \EcomailFlexibee\Http\HttpClient $httpClient;
    protected \EcomailFlexibee\Config $config;

    private \EcomailFlexibee\Http\ResponseHydrator $responseHydrator;

    public function __construct(
        string $url,
        string $company,
        string $user,
        string $password,
        string $evidence,
        bool $disableSelfSignedCertificate,
        ?string $authSessionId = null,
        ?string $logFilePath = null
    )
    {
        $this->config = new Config(
            $url,
            $company,
            $user,
            $password,
            $evidence,
            $disableSelfSignedCertificate,
            $authSessionId,
            $logFilePath,
        );
        $this->queryBuilder = new UrlBuilder($this->config);
        $this->responseHydrator = new ResponseHydrator($this->config);
        $this->httpClient = new HttpClient();
    }

    public function isAllowedChangesApi(): bool
    {
        return $this->httpClient->request(
            $this->queryBuilder->createChangesStatusUrl(),
            Method::get(Method::GET),
            [],
            [],
            [],
            $this->config,
        )->isSuccess();
    }

    public function getChangesApiForEvidence(string $evidenceName): Response
    {
        return $this->httpClient->request(
            $this->queryBuilder->createChangesUrl(['evidence' => $evidenceName]),
            Method::get(Method::GET),
            [],
            [],
            [],
            $this->config,
        );
    }

    public function getPropertiesForEvidence(): Response
    {
        return $this->httpClient->request(
            $this->queryBuilder->createUri('properties', []),
            Method::get(Method::GET),
            [],
            [],
            [],
            $this->config,
        );
    }

    public function getAllApiChanges(?string $fromVersion): Response
    {
        return $this->httpClient->request(
            $this->queryBuilder->createChangesUrl(['start' => $fromVersion]),
            Method::get(Method::GET),
            [],
            [],
            [],
            $this->config,
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
        return $this->httpClient->request(
            $this->queryBuilder->createAuthTokenUrl(),
            Method::get(Method::POST),
            [],
            [
                'username' => $this->config->getUser(),
                'password' => $this->config->getPassword(),
            ],
            [
                'Content-Type: application/x-www-form-urlencoded',
            ],
            $this->config,
        );
    }
    
    public function deleteById(int $id, bool $dryRun = false): Response
    {
        $uriParameters = $dryRun ? ['dry-run' => 'true'] : [];

        return $this->httpClient->request(
            $this->queryBuilder->createUri($id, $uriParameters),
            Method::get(Method::DELETE),
            [],
            [],
            [],
            $this->config,
        );
    }
    
    public function deleteByCode(string $id, bool $dryRun = false): void
    {
        $uriParameters = $dryRun ? ['dry-run' => 'true'] : [];
        $this->httpClient->request(
            $this->queryBuilder->createUri(\sprintf('code:%s', $id), $uriParameters),
            Method::get(Method::DELETE),
            [],
            [],
            [],
            $this->config,
        );
    }

    /**
     * @param int $id
     * @param array<mixed> $uriParameters
     * @return \EcomailFlexibee\Result\EvidenceResult
     * @throws \EcomailFlexibee\Exception\EcomailFlexibeeConnectionError
     * @throws \EcomailFlexibee\Exception\EcomailFlexibeeForbidden
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
     * @throws \EcomailFlexibee\Exception\EcomailFlexibeeForbidden
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
            $this->httpClient->request(
                $this->queryBuilder->createUriByCodeOnly($code, $uriParameters),
                Method::get(Method::GET),
                [],
                [],
                [],
                $this->config,
            ) ,
            true,
        );
    }

    /**
     * @param int $id
     * @param array<string> $uriParameters
     * @return \EcomailFlexibee\Result\EvidenceResult
     * @throws \EcomailFlexibee\Exception\EcomailFlexibeeConnectionError
     * @throws \EcomailFlexibee\Exception\EcomailFlexibeeForbidden
     * @throws \EcomailFlexibee\Exception\EcomailFlexibeeInvalidAuthorization
     * @throws \EcomailFlexibee\Exception\EcomailFlexibeeMethodNotAllowed
     * @throws \EcomailFlexibee\Exception\EcomailFlexibeeNoEvidenceResult
     * @throws \EcomailFlexibee\Exception\EcomailFlexibeeNotAcceptableRequest
     * @throws \EcomailFlexibee\Exception\EcomailFlexibeeRequestError
     */
    public function getById(int $id, array $uriParameters = []): EvidenceResult
    {
        return $this->responseHydrator->convertResponseToEvidenceResult(
            $this->httpClient->request(
                $this->queryBuilder->createUri($id, $uriParameters),
                Method::get(Method::GET),
                [],
                [],
                [],
                $this->config,
            ),
            true,
        );
    }

    /**
     * @param string $code
     * @param array<mixed> $uriParameters
     * @return \EcomailFlexibee\Result\EvidenceResult
     * @throws \EcomailFlexibee\Exception\EcomailFlexibeeConnectionError
     * @throws \EcomailFlexibee\Exception\EcomailFlexibeeForbidden
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
     * @throws \EcomailFlexibee\Exception\EcomailFlexibeeForbidden
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
        $uriParameters = $dryRun
            ? \array_merge($uriParameters, ['dry-run' => 'true'])
            : $uriParameters;
        /** @var \EcomailFlexibee\Result\EvidenceResult $response */
        $response = $this->callRequest(Method::get(Method::PUT), null, $uriParameters, $postData, [])[0];
        $data = $response->getData();

        if (isset($data['created']) && (int) $data['created'] === 0 && isset($data['updated']) && (int) $data['updated'] === 0) {
            $errorMessage = \sprintf('(%d) %s', $data['status_code'], $data['message']);

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
            \count($data),
            null,
            $response->getData(),
            [],
        );
    }

    public function getUserRelations(int $objectId): EvidenceResult
    {
        return new EvidenceResult(
            $this->getById($objectId, ['relations' => 'uzivatelske-vazby'])->getData()[0]['uzivatelske-vazby'],
        );
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
                    'object' => \sprintf('code:%s', $objectBData['kod']),
                ],
            ],
        ];

        $this->save($relationData, $objectAId);
    }

    /**
     * @return array<mixed>
     * @throws \EcomailFlexibee\Exception\EcomailFlexibeeConnectionError
     * @throws \EcomailFlexibee\Exception\EcomailFlexibeeForbidden
     * @throws \EcomailFlexibee\Exception\EcomailFlexibeeInvalidAuthorization
     * @throws \EcomailFlexibee\Exception\EcomailFlexibeeMethodNotAllowed
     * @throws \EcomailFlexibee\Exception\EcomailFlexibeeNotAcceptableRequest
     * @throws \EcomailFlexibee\Exception\EcomailFlexibeeRequestError
     */
    public function allInEvidence(): array
    {
        $response = $this->httpClient->request(
            $this->queryBuilder->createUriByEvidenceOnly(['limit' => 0]),
            Method::get(Method::GET),
            [],
            [],
            [],
            $this->config,
        );

        return $this->responseHydrator->convertResponseToEvidenceResults($response);
    }

    public function countInEvidence(): int
    {
        $response = $this->httpClient->request(
            $this->queryBuilder->createUriByEvidenceOnly(['add-row-count' => 'true']),
            Method::get(Method::GET),
            [],
            [],
            [],
            $this->config,
        );

       return $response->getRowCount() ?? 0;
    }

    /**
     * @param int $start
     * @param int $limit
     * @return array<\EcomailFlexibee\Result\EvidenceResult>
     * @throws \EcomailFlexibee\Exception\EcomailFlexibeeConnectionError
     * @throws \EcomailFlexibee\Exception\EcomailFlexibeeForbidden
     * @throws \EcomailFlexibee\Exception\EcomailFlexibeeInvalidAuthorization
     * @throws \EcomailFlexibee\Exception\EcomailFlexibeeMethodNotAllowed
     * @throws \EcomailFlexibee\Exception\EcomailFlexibeeNotAcceptableRequest
     * @throws \EcomailFlexibee\Exception\EcomailFlexibeeRequestError
     */
    public function chunkInEvidence(int $start, int $limit): array
    {
        $response = $this->httpClient->request(
            $this->queryBuilder->createUriByEvidenceOnly(['limit' => $limit, 'start' => $start]),
            Method::get(Method::GET),
            [],
            [],
            [],
            $this->config,
        );

        return $this->responseHydrator->convertResponseToEvidenceResults($response);
    }

    /**
     * @param string $query
     * @param array<string> $uriParameters
     * @return array<\EcomailFlexibee\Result\EvidenceResult>
     * @throws \EcomailFlexibee\Exception\EcomailFlexibeeConnectionError
     * @throws \EcomailFlexibee\Exception\EcomailFlexibeeForbidden
     * @throws \EcomailFlexibee\Exception\EcomailFlexibeeInvalidAuthorization
     * @throws \EcomailFlexibee\Exception\EcomailFlexibeeMethodNotAllowed
     * @throws \EcomailFlexibee\Exception\EcomailFlexibeeNotAcceptableRequest
     * @throws \EcomailFlexibee\Exception\EcomailFlexibeeRequestError
     */
    public function searchInEvidence(string $query, array $uriParameters): array
    {
        $response = $this->httpClient->request(
            $this->queryBuilder->createFilterQuery($query, $uriParameters),
            Method::get(Method::GET),
            [],
            [],
            [],
            $this->config,
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
        $response = $this->httpClient->request(
            $this->queryBuilder->createUri($queryFilterOrId, $uriParameters),
            $httpMethod,
            $postFields,
            [],
            $headers,
            $this->config,
        );

        return $this->responseHydrator->convertResponseToEvidenceResults($response);
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
        return $this->httpClient->request(
            $this->queryBuilder->createPdfUrl($id, $uriParameters),
            Method::get(Method::GET),
            [],
            [],
            [],
            $this->config,
        );
    }

}
