<?php

namespace Codefog\HasteBundle\EventListener;

use Codefog\HasteBundle\DcaRelationsManager;
use Codefog\HasteBundle\Event\UndoEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener(event: UndoEvent::NAME)]
class DcaRelationsListener
{
    public function __construct(
        private readonly DcaRelationsManager $dcaRelations,
    ) {}

    public function __invoke(UndoEvent $event)
    {
        $this->dcaRelations->undoRelations($event->getHasteData(), $event->getId(), $event->getTable(), $event->getRow());
    }
}