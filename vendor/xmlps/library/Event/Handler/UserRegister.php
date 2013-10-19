<?php

namespace Xmlps\Event\Handler;

use Zend\ServiceManager\ServiceLocatorAwareInterface;
use Zend\ServiceManager\ServiceLocatorInterface;

use Zend\Mail;

/**
 * Sends user registration notification and confirmation emails
 */
class UserRegisterHandler implements ServiceLocatorAwareInterface
{
    protected $sm;
    protected $translator;
    protected $config;
    protected $logger;

    protected $transport;

    protected $user;
    protected $adminEmail;

    /**
     * Set the service locator
     *
     * @param ServiceLocatorInterface $sm
     *
     * @return void
     */
    public function setServiceLocator(ServiceLocatorInterface $sm)
    {
        $this->sm = $sm;
    }

    /**
     * Get the service locator
     *
     * @return ServiceLocator ServiceLocator instance
     */
    public function getServiceLocator()
    {
        return $this->sm;
    }

    /**
     * Sends notification emails after user registration
     *
     * @param mixed $e Event
     *
     * @return void
     */
    public function sendEmails(&$e)
    {
        $this->translator = $this->sm->get('Translator');
        $this->config = $this->sm->get('config');
        $this->logger = $this->sm->get('Logger');

        $params = $e->getParams();

        if (
            !isset($params['user']) or
            !$this->user = $params['user']
        ) {
            throw new \Exception('User data is missing');
        }

        if (
            !isset($this->config['notification']['adminEmail']) or
            !$this->adminEmail = $this->config['notification']['adminEmail']
        ) {
            throw new \Exception('Admin email is not configured');
        }

        // Set up the transport
        $this->transport = $this->sm->get('MailTransport');

        // Send the confirmation email to the user
        $this->sendConfirmationEmail();

        // Send a notification to the admin
        $this->sendAdminNotificationEmail();
    }

    /**
     * Send a confirmation email to the user
     *
     * @return void
     */
    protected function sendConfirmationEmail()
    {
        $mail = $this->sm->get('Mail');
        $mail->setBody(
            $this->translator->translate('user.registration.confirmationEmailBody')
        );
        $mail->setFrom(
            $this->adminEmail,
            $this->translator->translate('application.generic.adminName')
        );
        $mail->addTo($this->user->email);
        $mail->setSubject(
            $this->translator->translate('user.registration.confirmationEmailSubject')
        );

        $this->logger->info(
            sprintf(
                $this->translator->translate('user.registration.confirmationEmailSentLog'),
                $this->user->email
            )
        );

        $this->transport->send($mail);
    }

    /**
     * Send a notification email to the admin
     *
     * @return void
     */
    protected function sendAdminNotificationEmail()
    {
        $mail = $this->sm->get('Mail');
        $mail->setBody(
            sprintf(
                $this->translator->translate('user.registration.adminNotificationEmailBody'),
                $this->user->email
            )
        );
        $mail->setFrom(
            $this->adminEmail,
            $this->translator->translate('application.generic.adminName')
        );
        $mail->addTo($this->user->email);
        $mail->setSubject(
            $this->translator->translate('user.registration.adminNotificationEmailSubject')
        );

        $this->logger->info(
            sprintf(
                $this->translator->translate('user.registration.adminNotificationEmailSentLog'),
                $this->adminEmail
            )
        );

        $this->transport->send($mail);
    }
}
