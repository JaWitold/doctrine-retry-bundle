<?php

declare(strict_types=1);

namespace DualMedia\DoctrineRetryBundle\Tests\Unit;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use DualMedia\DoctrineRetryBundle\Event\TransactionFailedEvent;
use DualMedia\DoctrineRetryBundle\Event\TransactionFinalizedEvent;
use DualMedia\DoctrineRetryBundle\Event\TransactionStartEvent;
use DualMedia\DoctrineRetryBundle\Interface\PassthroughExceptionInterface;
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

    public function testPassthroughExceptionBypassesRetryAndEventDispatch(): void
    {
        $passThroughException = new class('pass-through') extends \RuntimeException implements PassthroughExceptionInterface {};

        $connection = $this->createMock(Connection::class);
        $connection->expects(static::never())
            ->method('isTransactionActive');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(static::never())
            ->method('close');
        $em->expects(static::never())
            ->method('getConnection');
        $em->expects(static::once())
            ->method('rollback');
        $em->expects(static::once())
        ->method('clear');

        $this->getMockedService(ManagerRegistry::class)
            ->expects(static::once())
            ->method('getManager')
            ->willReturn($em);

        $this->getMockedService(EventDispatcherInterface::class)
            ->expects(static::exactly(3))
            ->method('dispatch')
            ->with(static::logicalOr(
                static::isInstanceOf(TransactionStartEvent::class),
                static::isInstanceOf(TransactionFailedEvent::class),
                static::isInstanceOf(TransactionFinalizedEvent::class),
            ));

        $this->expectException($passThroughException::class);
        $this->expectExceptionMessage('pass-through');

        $this->service->execute(static function () use ($passThroughException): void {
            throw $passThroughException;
        });
    }
}
