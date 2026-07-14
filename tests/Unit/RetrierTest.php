<?php

declare(strict_types=1);

namespace DualMedia\DoctrineRetryBundle\Tests\Unit;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use DualMedia\DoctrineRetryBundle\Event\TransactionFinalizedEvent;
use DualMedia\DoctrineRetryBundle\Interface\PassThroughExceptionInterface;
use DualMedia\DoctrineRetryBundle\Retrier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pkly\ServiceMockHelperTrait;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

#[CoversClass(Retrier::class)]
class RetrierTest extends TestCase
{
    use ServiceMockHelperTrait;

    private Retrier $service;

    protected function setUp(): void
    {
        $this->service = $this->createRealMockedServiceInstance(Retrier::class, [
            'trackNesting' => false,
        ]);
    }

    public function testExecute(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);

        $this->getMockedService(ManagerRegistry::class)
            ->expects(static::once())
            ->method('getManager')
            ->willReturn($em);

        $this->service->execute(function () {});
    }

    public function testPassThroughExceptionBypassesRetryAndEventDispatch(): void
    {
        $passThroughException = new class('pass-through') extends \RuntimeException implements PassThroughExceptionInterface {};

        $connection = $this->createMock(Connection::class);
        $connection->expects(static::never())
            ->method('isTransactionActive');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(static::never())
            ->method('close');
        $em->expects(static::never())
            ->method('getConnection');

        $this->getMockedService(ManagerRegistry::class)
            ->expects(static::once())
            ->method('getManager')
            ->willReturn($em);

        $this->getMockedService(EventDispatcherInterface::class)
            ->expects(static::once())
            ->method('dispatch')
            ->with(static::callback(static function (mixed $event) use ($em): bool {
                return $event instanceof TransactionFinalizedEvent
                    && false === $event->success
                    && false === $event->rollback
                    && 0 === $event->attempt
                    && $event->em === $em;
            }));

        $this->expectException($passThroughException::class);
        $this->expectExceptionMessage('pass-through');

        $this->service->execute(static function () use ($passThroughException): void {
            throw $passThroughException;
        });
    }
}
