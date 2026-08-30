<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Http;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use TriCab\Api\Tests\TestAppFactory;

final class ApprovalCertificateEndpointsTest extends TestCase
{
    public function test_certificate_master_image_assign_pending_and_reject_inactive(): void
    {
        $app = TestAppFactory::create([]);

        $group = TestAppFactory::json($app->handle($this->jsonRequest('POST', '/api/v1/admin/specification-groups', [
            'name' => 'Compliance',
            'code' => 'COMPLIANCE',
        ])))['data'];

        $approvals = TestAppFactory::json($app->handle($this->jsonRequest('POST', '/api/v1/admin/specification-definitions', [
            'name' => 'Approvals',
            'code' => 'APPROVALS',
            'dataTypeCode' => 'multi-select',
            'scopeCode' => 'product',
            'groupPublicId' => $group['publicId'],
        ])))['data'];

        $dnv = TestAppFactory::json($app->handle($this->jsonRequest('POST', '/api/v1/admin/specification-options', [
            'definitionPublicId' => $approvals['publicId'],
            'name' => 'DNV',
            'code' => 'DNV',
            'value' => 'DNV',
            'imageUrl' => '/certificates/DNV_logo_RGB_tcm8-197625.webp',
            'displayOrder' => 10,
        ])))['data'];
        $absResponse = $app->handle($this->jsonRequest('POST', '/api/v1/admin/specification-options', [
            'definitionPublicId' => $approvals['publicId'],
            'name' => 'ABS',
            'code' => 'ABS',
            'value' => 'ABS',
            'displayOrder' => 20,
        ]));
        self::assertSame(201, $absResponse->getStatusCode(), (string) $absResponse->getBody());
        $absOption = TestAppFactory::json($absResponse)['data'];
        self::assertSame('DNV', $dnv['code']);
        self::assertSame('DNV', $dnv['value']);
        self::assertSame('/certificates/DNV_logo_RGB_tcm8-197625.webp', $dnv['imageUrl']);

        $inactive = TestAppFactory::json($app->handle($this->jsonRequest('POST', '/api/v1/admin/specification-options', [
            'definitionPublicId' => $approvals['publicId'],
            'name' => 'Lloyds',
            'code' => 'LR',
            'value' => "Lloyd's Register",
            'isActive' => false,
        ])))['data'];
        self::assertFalse($inactive['isActive']);

        $product = TestAppFactory::json($app->handle($this->jsonRequest('POST', '/api/v1/admin/products', [
            'productName' => 'Certificate Assignment Product',
        ])))['data'];
        $productPublicId = $product['publicId'];

        $assign = $app->handle($this->jsonRequest('PUT', '/api/v1/admin/products/' . $productPublicId . '/specifications', [
            'values' => [[
                'definitionPublicId' => $approvals['publicId'],
                'optionPublicIds' => [$dnv['publicId'], $absOption['publicId']],
                'optionStatuses' => [
                    $dnv['publicId'] => 'pending',
                    $absOption['publicId'] => 'approved',
                ],
            ]],
        ]));
        self::assertSame(200, $assign->getStatusCode(), (string) $assign->getBody());

        $loaded = TestAppFactory::json($app->handle(TestAppFactory::request(
            'GET',
            '/api/v1/admin/products/' . $productPublicId . '/specifications',
        )))['data']['values'];
        $approvalsValue = null;
        foreach ($loaded as $value) {
            if (($value['definitionPublicId'] ?? '') === $approvals['publicId']) {
                $approvalsValue = $value;
            }
        }
        self::assertIsArray($approvalsValue);
        self::assertEqualsCanonicalizing(
            [$dnv['publicId'], $absOption['publicId']],
            $approvalsValue['optionPublicIds'],
        );
        self::assertSame('pending', $approvalsValue['optionStatuses'][$dnv['publicId']]);
        self::assertSame('approved', $approvalsValue['optionStatuses'][$absOption['publicId']]);

        $inactiveAssign = $app->handle($this->jsonRequest('PUT', '/api/v1/admin/products/' . $productPublicId . '/specifications', [
            'values' => [[
                'definitionPublicId' => $approvals['publicId'],
                'optionPublicIds' => [$inactive['publicId']],
            ]],
        ]));
        self::assertSame(422, $inactiveAssign->getStatusCode(), (string) $inactiveAssign->getBody());

        $remove = $app->handle($this->jsonRequest('PUT', '/api/v1/admin/products/' . $productPublicId . '/specifications', [
            'values' => [[
                'definitionPublicId' => $approvals['publicId'],
                'optionPublicIds' => [$absOption['publicId']],
                'optionStatuses' => [$absOption['publicId'] => 'approved'],
            ]],
        ]));
        self::assertSame(200, $remove->getStatusCode(), (string) $remove->getBody());
        $afterRemove = TestAppFactory::json($app->handle(TestAppFactory::request(
            'GET',
            '/api/v1/admin/products/' . $productPublicId . '/specifications',
        )))['data']['values'];
        $remaining = null;
        foreach ($afterRemove as $value) {
            if (($value['definitionPublicId'] ?? '') === $approvals['publicId']) {
                $remaining = $value;
            }
        }
        self::assertIsArray($remaining);
        self::assertSame([$absOption['publicId']], $remaining['optionPublicIds']);
        self::assertArrayNotHasKey($dnv['publicId'], $remaining['optionStatuses']);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function jsonRequest(string $method, string $path, array $body = []): ServerRequestInterface
    {
        $request = TestAppFactory::request($method, $path)
            ->withHeader('Content-Type', 'application/json');
        $request->getBody()->write(json_encode($body, JSON_THROW_ON_ERROR));

        return $request;
    }
}
