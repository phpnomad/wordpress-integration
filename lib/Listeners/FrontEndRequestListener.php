<?php

namespace PHPNomad\Integrations\WordPress\Listeners;

use PHPNomad\Events\Interfaces\CanListen;
use PHPNomad\Events\Interfaces\EventStrategy;
use PHPNomad\Framework\Events\SiteVisited;

class FrontEndRequestListener implements CanListen
{
    protected EventStrategy $eventStrategy;

    public function __construct(EventStrategy $eventStrategy)
    {
        $this->eventStrategy = $eventStrategy;
    }
    public function listen(): void
    {
        add_action('init', function(){
            $isNotFrontendRequest =
                is_admin()
                || wp_doing_ajax()
                || defined('REST_REQUEST') && REST_REQUEST
                || (defined('WP_CLI') && WP_CLI)
                || defined('XMLRPC_REQUEST') && XMLRPC_REQUEST
                || defined('DOING_CRON') && DOING_CRON;

            if($isNotFrontendRequest){
                return;
            }

            $userId = get_current_user_id();
            $userId = $userId <= 0 ? null : $userId;

            $this->eventStrategy->broadcast(new SiteVisited($userId));
        });
    }
}
