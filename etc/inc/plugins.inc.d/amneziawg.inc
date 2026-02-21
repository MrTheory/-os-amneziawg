<?php

function amneziawg_services()
{
    $services = [];
    $services[] = [
        'description' => 'AmneziaWG',
        'configd'     => [
            'restart' => ['amneziawg restart'],
            'start'   => ['amneziawg start'],
            'stop'    => ['amneziawg stop'],
        ],
        'name'    => 'amneziawg',
        'pidfile' => '/var/run/amneziawg.pid',
    ];
    return $services;
}

function amneziawg_xmlrpc_sync()
{
    return [];
}
