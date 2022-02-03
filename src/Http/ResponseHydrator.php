<?php

declare(strict_types = 1);

namespace EcomailFlexibee\Http;

use Consistence\ObjectPrototype;
use EcomailFlexibee\Config;
use EcomailFlexibee\Exception\EcomailFlexibeeNoEvidenceResult;
use EcomailFlexibee\Exception\EcomailFlexibeeRequestFail;
use EcomailFlexibee\Http\Response\Response;
use EcomailFlexibee\Result\EvidenceResult;
use function array_map;
use function count;

class ResponseHydrator extends ObjectPrototype
{

    private Config $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * @return array<\EcomailFlexibee\Result\EvidenceResult>
     * @throws \EcomailFlexibee\Exception\EcomailFlexibeeRequestFail
     */
    public function convertResponseToEvidenceResults(Response $response): array
    {
        $this->checkForDataErrors($response);
        $data = $response->getData();

        if (!$this->hasDataForEvidence($data)) {
            return $this->getNonEvidenceData($response);
        }

        return array_map(static fn (array $data) => new EvidenceResult($data), $data[$this->config->getEvidence()]);
    }

    /**
     * @return array<mixed>
     * @throws \EcomailFlexibee\Exception\EcomailFlexibeeRequestFail
     */
    public function convertResponseToPaginatedEvidenceResults(Response $response): array
    {
        $this->checkForDataErrors($response);
        $data = $response->getData();

        if (!$this->hasDataForEvidence($data)) {
            return $this->getNonEvidenceData($response);
        }

        return [
            'row_count' => $response->getRowCount(),
            'data' => array_map(static fn (array $data) => new EvidenceResult($data), $data[$this->config->getEvidence()]),
        ];
    }

    public function convertResponseToEvidenceResult(Response $response, bool $throwException): EvidenceResult
    {
        $data = $response->getData();

        if ($response->getStatusCode() === 404 || !isset($data[$this->config->getEvidence()])) {
            if ($throwException) {
                throw new EcomailFlexibeeNoEvidenceResult();
            }

            return count($data) !== 0
                ? new EvidenceResult($data)
                : new EvidenceResult([]);
        }

        return new EvidenceResult($data[$this->config->getEvidence()]);
    }

    /**
     * @throws \EcomailFlexibee\Exception\EcomailFlexibeeRequestFail
     */
    public function checkForDataErrors(Response $response): void
    {
        $data = $response->getData();
        $hasErrors = isset($data[0], $data[0]['errors']);

        if (!$hasErrors && (!isset($data['success']) || $data['success'] !== 'false')) {
            return;
        }

        if ($hasErrors && !isset($data['message'])) {
            $data['message'] = '';

            foreach ($data[0]['errors'] as $errors) {
                $data['message'] .= "\n" . $errors['message'];
            }
        }

        throw new EcomailFlexibeeRequestFail($data['message'], $response->getStatusCode());
    }

    /**
     * @param array<mixed> $data
     */
    private function hasDataForEvidence(array $data): bool
    {
        return isset($data[$this->config->getEvidence()]);
    }

    /**
     * @return array<\EcomailFlexibee\Result\EvidenceResult>
     */
    private function getNonEvidenceData(Response $response): array
    {
        if (count($response->getData()) === 0) {
            $data = $response->getStatistics();
            $data['status_code'] = $response->getStatusCode();
            $data['message'] = $response->getMessage();
            $data['version'] = $response->getVersion();
            $data['row_count'] = $response->getRowCount();
        }

        return [new EvidenceResult($response->getData())];
    }

}
