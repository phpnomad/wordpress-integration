<?php

namespace Phoenix\Core\Strategies;

use Phoenix\Core\Bootstrap\Interfaces\EventStrategy;
use Phoenix\Core\Events\Interfaces\Event;

class WordPressEventStrategy implements EventStrategy
{
    /**
     * @param Event|class-string<Event> $event
     * @return string
     */
    protected function getActionName($event): string
    {
        return 'phoenix/' . $event::getId();
    }

    public function broadcast(Event $event): void
    {
        do_action($this->getActionName($event), $event);
    }

    /**
     * @param class-string<Event> $event
     * @param callable $action
     * @param int|null $priority
     * @return void
     */
    public function attach(string $event, callable $action, ?int $priority): void
    {
        add_action($this->getActionName($event), $action, is_null($priority) ? 10 : $priority, 1);
    }

    /**
     * @param class-string<Event> $event
     * @param callable $action
     * @param int|null $priority
     * @return void
     */
    public function detach(string $event, callable $action, ?int $priority): void
    {
        remove_action($this->getActionName($event), $action, is_null($priority) ? 10 : $priority);
    }
}