<?php
return array(
    'acl' => array(
        'resources' => array(
            'controller:SlmQueueDoctrine\Controller\DoctrineWorkerController:process',
        ),
        'rules' => array(
            array('allow', 'guest', 'controller:SlmQueueDoctrine\Controller\DoctrineWorkerController:process'),
        ),
    ),
    'slm_queue' => array(
        'doctrine' => array(
            'connection' => 'doctrine.connection.orm_default',
            'table_name' => 'queue',
        ),
        'job_manager' => array(
            'factories' => array(
                'DocxConversion\Model\Queue\Job\DocxJob' => function($locator) {
                    return new DocxConversion\Model\Queue\Job\DocxJob();
                }
            ),
        ),
        'queue_manager' => array(
            'factories' => array(
                'docx' => 'SlmQueueDoctrine\Factory\DoctrineQueueFactory',
            ),
        ),
    ),
);