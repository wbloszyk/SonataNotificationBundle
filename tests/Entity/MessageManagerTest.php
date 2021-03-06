<?php

declare(strict_types=1);

/*
 * This file is part of the Sonata Project package.
 *
 * (c) Thomas Rabaix <thomas.rabaix@sonata-project.org>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Sonata\NotificationBundle\Tests\Entity;

use Doctrine\Common\Persistence\ManagerRegistry;
use Doctrine\Common\Persistence\Mapping\ClassMetadata;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\TestCase;
use Sonata\NotificationBundle\Entity\BaseMessage;
use Sonata\NotificationBundle\Entity\MessageManager;
use Sonata\NotificationBundle\Model\MessageInterface;

class MessageManagerTest extends TestCase
{
    public function testCancel()
    {
        $manager = $this->getMessageManagerMock();

        $message = $this->getMessage();

        $manager->cancel($message);

        $this->assertTrue($message->isCancelled());
    }

    public function testRestart()
    {
        $manager = $this->getMessageManagerMock();

        // test un-restartable status
        $this->assertNull($manager->restart($this->getMessage(MessageInterface::STATE_OPEN)));
        $this->assertNull($manager->restart($this->getMessage(MessageInterface::STATE_CANCELLED)));
        $this->assertNull($manager->restart($this->getMessage(MessageInterface::STATE_IN_PROGRESS)));

        $message = $this->getMessage(MessageInterface::STATE_ERROR);
        $message->setRestartCount(12);

        $newMessage = $manager->restart($message);

        $this->assertSame(MessageInterface::STATE_OPEN, $newMessage->getState());
        $this->assertSame(13, $newMessage->getRestartCount());
    }

    public function testGetPager()
    {
        $self = $this;
        $this
            ->getMessageManager(static function ($qb) use ($self) {
                $qb->expects($self->once())->method('getRootAliases')->will($self->returnValue(['m']));
                $qb->expects($self->never())->method('andWhere');
                $qb->expects($self->once())->method('setParameters')->with([]);
                $qb->expects($self->once())->method('orderBy')->with(
                    $self->equalTo('m.type'),
                    $self->equalTo('ASC')
                );
            })
            ->getPager([], 1);
    }

    public function testGetPagerWithInvalidSort()
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid sort field \'invalid\' in \'Sonata\\NotificationBundle\\Entity\\BaseMessage\' class');

        $self = $this;
        $this
            ->getMessageManager(static function ($qb) use ($self) {
            })
            ->getPager([], 1, 10, ['invalid' => 'ASC']);
    }

    public function testGetPagerWithMultipleSort()
    {
        $self = $this;
        $this
            ->getMessageManager(static function ($qb) use ($self) {
                $qb->expects($self->once())->method('getRootAliases')->will($self->returnValue(['m']));
                $qb->expects($self->never())->method('andWhere');
                $qb->expects($self->once())->method('setParameters')->with([]);
                $qb->expects($self->exactly(2))->method('orderBy')->with(
                    $self->logicalOr(
                        $self->equalTo('m.type'),
                        $self->equalTo('m.state')
                    ),
                    $self->logicalOr(
                        $self->equalTo('ASC'),
                        $self->equalTo('DESC')
                    )
                );
                $qb->expects($self->once())->method('setParameters')->with($self->equalTo([]));
            })
            ->getPager([], 1, 10, [
                'type' => 'ASC',
                'state' => 'DESC',
            ]);
    }

    public function testGetPagerWithOpenedMessages()
    {
        $self = $this;
        $this
            ->getMessageManager(static function ($qb) use ($self) {
                $qb->expects($self->once())->method('getRootAliases')->will($self->returnValue(['m']));
                $qb->expects($self->once())->method('andWhere')->with($self->equalTo('m.state = :state'));
                $qb->expects($self->once())->method('setParameters')->with($self->equalTo([
                    'state' => MessageInterface::STATE_OPEN,
                ]));
            })
            ->getPager(['state' => MessageInterface::STATE_OPEN], 1);
    }

    public function testGetPagerWithCanceledMessages()
    {
        $self = $this;
        $this
            ->getMessageManager(static function ($qb) use ($self) {
                $qb->expects($self->once())->method('getRootAliases')->will($self->returnValue(['m']));
                $qb->expects($self->once())->method('andWhere')->with($self->equalTo('m.state = :state'));
                $qb->expects($self->once())->method('setParameters')->with($self->equalTo([
                    'state' => MessageInterface::STATE_CANCELLED,
                ]));
            })
            ->getPager(['state' => MessageInterface::STATE_CANCELLED], 1);
    }

    public function testGetPagerWithInProgressMessages()
    {
        $self = $this;
        $this
            ->getMessageManager(static function ($qb) use ($self) {
                $qb->expects($self->once())->method('getRootAliases')->will($self->returnValue(['m']));
                $qb->expects($self->once())->method('andWhere')->with($self->equalTo('m.state = :state'));
                $qb->expects($self->once())->method('setParameters')->with($self->equalTo([
                    'state' => MessageInterface::STATE_IN_PROGRESS,
                ]));
            })
            ->getPager(['state' => MessageInterface::STATE_IN_PROGRESS], 1);
    }

    /**
     * @return MessageManagerMock
     */
    protected function getMessageManagerMock()
    {
        $registry = $this->createMock(ManagerRegistry::class);

        $manager = new MessageManagerMock(Message::class, $registry);

        return $manager;
    }

    /**
     * @return MessageManager
     */
    protected function getMessageManager($qbCallback)
    {
        $query = $this->getMockForAbstractClass(
            AbstractQuery::class,
            [],
            '',
            false,
            true,
            true,
            ['execute']
        );
        $query->expects($this->any())->method('execute')->willReturn(true);

        $qb = $this->getMockBuilder(QueryBuilder::class)
            ->setConstructorArgs([$this->createMock(EntityManager::class)])
            ->getMock();

        $qb->expects($this->any())->method('select')->willReturn($qb);
        $qb->expects($this->any())->method('getQuery')->willReturn($query);

        $qbCallback($qb);

        $repository = $this->createMock(EntityRepository::class);
        $repository->expects($this->any())->method('createQueryBuilder')->willReturn($qb);

        $metadata = $this->createMock(ClassMetadata::class);
        $metadata->expects($this->any())->method('getFieldNames')->willReturn([
            'state',
            'type',
        ]);

        $em = $this->createMock(EntityManager::class);
        $em->expects($this->any())->method('getRepository')->willReturn($repository);
        $em->expects($this->any())->method('getClassMetadata')->willReturn($metadata);

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->expects($this->any())->method('getManagerForClass')->willReturn($em);

        return  new MessageManager(BaseMessage::class, $registry);
    }

    /**
     * @param int $state
     *
     * @return Message
     */
    protected function getMessage($state = MessageInterface::STATE_OPEN)
    {
        $message = new Message();

        $message->setState($state);

        return $message;
    }
}
