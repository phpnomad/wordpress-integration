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
                || rest_doing_request()
                || (defined('WP_CLI') && WP_CLI)
                || defined('XMLRPC_REQUEST') && XMLRPC_REQUEST
                || defined('DOING_CRON') && DOING_CRON;

            if($isNotFrontendRequest){
                return;
            }

            $this->eventStrategy->broadcast(new SiteVisited());
        });
    }
}