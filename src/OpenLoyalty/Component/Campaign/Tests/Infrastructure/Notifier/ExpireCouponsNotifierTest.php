<?php
/**
 * Copyright © 2018 Divante, Inc. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace OpenLoyalty\Component\Campaign\Tests\Infrastructure\Infrastructure\Notifier;

use Broadway\CommandHandling\CommandBus;
use OpenLoyalty\Component\Campaign\Domain\Model\Coupon;
use OpenLoyalty\Component\Campaign\Infrastructure\Notifier\ExpireCouponsNotifier;
use OpenLoyalty\Component\Customer\Domain\Model\CampaignPurchase;
use OpenLoyalty\Component\Customer\Domain\ReadModel\CustomerDetails;
use OpenLoyalty\Component\Customer\Domain\ReadModel\CustomerDetailsRepository;
use Psr\Log\LoggerInterface;

/**
 * Class ExpireCouponsNotifierTest.
 */
class ExpireCouponsNotifierTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var CommandBus|\PHPUnit_Framework_MockObject_MockObject
     */
    private $commandBusMock;

    /**
     * @var LoggerInterface|\PHPUnit_Framework_MockObject_MockObject
     */
    private $loggerMock;

    /**
     * @var CustomerDetailsRepository|\PHPUnit_Framework_MockObject_MockObject
     */
    private $customerDetailsRepositoryMock;

    /**
     * {@inheritdoc}
     */
    public function setUp(): void
    {
        $this->commandBusMock = $this->getMockForAbstractClass(CommandBus::class);
        $this->loggerMock = $this->getMockForAbstractClass(LoggerInterface::class);
        $this->customerDetailsRepositoryMock = $this->getMockForAbstractClass(CustomerDetailsRepository::class);
    }

    /**
     * @test
     * @dataProvider getCampaignBoughtWithCouponsExpirationDateDataProvider
     *
     * @param array $couponsWillExpireIn
     * @param int   $expectedNotificationsCount
     * @param int   $expectedSentNotificationsCount
     */
    public function it_dispatches_webhook_dispatch_command(
        array $couponsWillExpireIn,
        int $expectedNotificationsCount,
        int $expectedSentNotificationsCount
    ): void {
        $campaignPurchaseMocks = [];

        foreach ($couponsWillExpireIn as $coupon) {
            $couponMock = $this
                ->getMockBuilder(Coupon::class)
                ->disableOriginalConstructor()
                ->getMock();
            $couponMock->method('getCode')->willReturn('super-code');

            $campaignPurchaseMock = $this
                ->getMockBuilder(CampaignPurchase::class)
                ->disableOriginalConstructor()
                ->getMock();
            $campaignPurchaseMock->method('getActiveTo')->willReturn(new \DateTime('+'.$coupon['days'].' days'));
            $campaignPurchaseMock->method('getStatus')->willReturn('active');
            $campaignPurchaseMock->method('getCoupon')->willReturn($couponMock);

            $campaignPurchaseMocks[] = $campaignPurchaseMock;
        }

        /** @var CustomerDetails|\PHPUnit_Framework_MockObject_MockObject $customerDetailsMock */
        $customerDetailsMock = $this
            ->getMockBuilder(CustomerDetails::class)
            ->disableOriginalConstructor()
            ->getMock()
        ;
        $customerDetailsMock->method('getCustomerId')->willReturn('22200000-0000-474c-b092-b0dd880c07e2');
        $customerDetailsMock->method('getEmail')->willReturn('test@example.com');
        $customerDetailsMock->method('getPhone')->willReturn('111222333');
        $customerDetailsMock->method('getLoyaltyCardNumber')->willReturn('test');
        $customerDetailsMock->method('getCampaignPurchases')->willReturn($campaignPurchaseMocks);

        $this->customerDetailsRepositoryMock
            ->method('findCustomersWithPurchasesExpiringAfter')
            ->willReturn([$customerDetailsMock])
        ;

        $expirePointsNotifier = new ExpireCouponsNotifier(
            $this->commandBusMock,
            $this->customerDetailsRepositoryMock,
            $this->loggerMock
        );
        $willExpireInFiveDays = new \DateTime('now');
        $willExpireInFiveDays->add(new \DateInterval('P5D'));
        $expirePointsNotifier->sendNotificationsForCouponsExpiringAfter($willExpireInFiveDays);

        $this->assertEquals($expectedNotificationsCount, $expirePointsNotifier->notificationsCount());
        $this->assertEquals($expectedSentNotificationsCount, $expirePointsNotifier->sentNotificationsCount());
    }

    /**
     * @return array
     */
    public function getCampaignBoughtWithCouponsExpirationDateDataProvider(): array
    {
        return [
            [[['days' => 1], ['days' => 2], ['days' => 3]], 0, 0],
            [[['days' => 6], ['days' => 7], ['days' => 8]], 0, 0],
            [[['days' => 5], ['days' => 5], ['days' => 3]], 2, 1],
        ];
    }

    /**
     * @test
     */
    public function it_does_not_dispatch_webhook_command_when_there_are_no_expiring_coupons(): void
    {
        $this->customerDetailsRepositoryMock
            ->method('findCustomersWithPurchasesExpiringAfter')
            ->willReturn([])
        ;

        $expirePointsNotifier = new ExpireCouponsNotifier(
            $this->commandBusMock,
            $this->customerDetailsRepositoryMock,
            $this->loggerMock
        );
        $expirePointsNotifier->sendNotificationsForCouponsExpiringAfter(new \DateTime('now'));

        $this->assertEquals(0, $expirePointsNotifier->sentNotificationsCount());
        $this->assertEquals(0, $expirePointsNotifier->notificationsCount());
    }
}