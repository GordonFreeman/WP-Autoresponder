<?php

    class AutoresponderProcessor
    {
        private static $processor;

        public function run() {
            $time = new DateTime();
            $this->run_for_time($time);
        }

        public function run_for_time(DateTime $time) {
            $this->process_messages($time);
        }

        private function getNumberOfAutoresponderMessages() {
            global $wpdb;
            return AutoresponderMessage::getAllMessagesCount();
        }

        private function process_messages(DateTime $currentTime) {

            $number_of_messages = $this->getNumberOfAutoresponderMessages();
            $number_of_iterations = ceil($number_of_messages/ $this->autoresponder_messages_loop_iteration_size());

            for ($iter=0;$iter< $number_of_iterations; $iter++) {

                $start = ($iter*$this->autoresponder_messages_loop_iteration_size());
                $messages = AutoresponderMessage::getAllMessages($start, $this->autoresponder_messages_loop_iteration_size());

                foreach ($messages as $message) {
                    $this->deliver_message($message, $currentTime);
                }
            }
        }

        private function autoresponder_messages_loop_iteration_size()
        {
            //this will be dynamic later on.
            return 10;
        }

        private function deliver_message(AutoresponderMessage $message, DateTime $time) {

            $numberOfSubscribers = $this->getNumberOfRecipientSubscribers($message, $time);

            $numberOfIterations = ceil($numberOfSubscribers/ $this->subscribers_processor_iteration_size());


            for ($iter=1; $iter <= $numberOfIterations; $iter++ ) {

                $start = ($iter-1)*$this->subscribers_processor_iteration_size();
                $subscribers = $this->getNextRecipientBatch($message, $time->getTimestamp(), $this->subscribers_processor_iteration_size());

                for ($subiter=0;$subiter< count($subscribers); $subiter++) {
                    $this->deliver($subscribers[$subiter], $message, $time);
                }
            }

        }

        private function subscribers_processor_iteration_size()
        {
            return 1000;
        }


        private function deliver($subscriber, AutoresponderMessage $message, DateTime $time) {

            global $wpdb;
            $htmlBody = $message->getHTMLBody();

            $htmlenabled = (!empty($htmlBody))?1:0;

            $params= array(
                'meta_key'=> sprintf('AR-%d-%d-%d-%d', $message->getAutoresponder()->getId(), $subscriber->sid, $message->getId(), $message->getDayNumber()),
                'htmlbody' => $message->getHTMLBody(),
                'textbody' => $message->getTextBody(),
                'subject' => $message->getSubject(),
                'htmlenabled'=> $htmlenabled
            );

            sendmail($subscriber->sid, $params);

            $updateSubscriptionMarkingItAsProcessedForCurrentDay = sprintf("UPDATE %swpr_followup_subscriptions SET sequence=%d, last_date=%d WHERE id=%d", $wpdb->prefix, $message->getDayNumber(), $time->getTimestamp(), $subscriber->id);

            $wpdb->query($updateSubscriptionMarkingItAsProcessedForCurrentDay);

        }

        private function getNumberOfRecipientSubscribers(AutoresponderMessage $message, DateTime $time) {

            global $wpdb;

            $columnUsedForReference = $this->getColumnUsedForReference($message);
            $additionalCondition = $this->additionalConditionsForQuery($message);
            $dayOffsetOfMessage = $message->getDayNumber();
            $previous_message_offset = $message->getPreviousMessageDayNumber();

            $currentTime = $time->getTimestamp();
            $getSubscribersQuery = sprintf("SELECT COUNT(*) num  FROM %swpr_followup_subscriptions subscriptions, %swpr_subscribers subscribers
                                                                 WHERE
                                                                 `subscriptions`.`sid`=`subscribers`.`id` AND
                                                                 (
                                                                    FLOOR((%d-`subscriptions`.`{$columnUsedForReference}`)/86400)=%d OR
                                                                    (
                                                                      FLOOR((%d-`subscriptions`.`{$columnUsedForReference}`)/86400) > %d AND
                                                                      `sequence` = %d
                                                                    )


                                                                 ) AND
                                                                 {$additionalCondition}
                                                                 `subscriptions`.`eid`=%d AND
                                                                 `type`='autoresponder' AND
                                                                 `subscribers`.active=1 AND `subscribers`.confirmed=1 AND
                                                                 `subscriptions`.`sequence` <> %d;",  $wpdb->prefix, $wpdb->prefix, $currentTime, $dayOffsetOfMessage, $currentTime, $dayOffsetOfMessage, $previous_message_offset, $message->getAutoresponder()->getId(), $dayOffsetOfMessage );

            $numbers = $wpdb->get_results($getSubscribersQuery);
            $number = $numbers[0];
            return $number->num;
        }

        private function getNextRecipientBatch(AutoresponderMessage $message, $currentTime, $size=-1) {

            global $wpdb;

            $columnUsedForReference = $this->getColumnUsedForReference($message);
            $additionalCondition = $this->additionalConditionsForQuery($message);

            $dayOffsetOfMessage = $message->getDayNumber();
            $previous_message_offset = $message->getPreviousMessageDayNumber();

            $limitClause = '';
            if ($size > 0) {
                $limitClause = "LIMIT {$size}";
            }

            $getSubscribersQuery = sprintf("SELECT subscriptions.*  FROM %swpr_followup_subscriptions subscriptions, %swpr_subscribers subscribers
                                                                 WHERE
                                                                 `subscriptions`.`sid`=`subscribers`.`id` AND
                                                                 (
                                                                    FLOOR((%d-`subscriptions`.`{$columnUsedForReference}`)/86400)=%d OR
                                                                    (
                                                                      FLOOR((%d-`subscriptions`.`{$columnUsedForReference}`)/86400) > %d AND
                                                                      `sequence` = %d
                                                                    )
                                                                 ) AND
                                                                 {$additionalCondition}
                                                                 `subscriptions`.`eid`=%d AND
                                                                 `type`='autoresponder' AND
                                                                 `subscribers`.active=1 AND `subscribers`.confirmed=1 AND
                                                                 `subscriptions`.`sequence` <> %d
                                                                 ORDER BY sid ASC
                                                                 %s;",  $wpdb->prefix, $wpdb->prefix, $currentTime, $dayOffsetOfMessage, $currentTime, $dayOffsetOfMessage, $previous_message_offset, $message->getAutoresponder()->getId(), $dayOffsetOfMessage, $limitClause );


            $subscribers = $wpdb->get_results($getSubscribersQuery);

            return $subscribers;

        }

        private function additionalConditionsForQuery($message)
        {
            $additionalCondition = '';
            if ($this->whetherFirstMessageOfAutoresponder($message)) {
                $additionalCondition = " last_date = 0 AND";
                return $additionalCondition;
            }
            return $additionalCondition;
        }

        private function getColumnUsedForReference($message)
        {
            $columnUsedForReference = 'last_date';
            if ($this->whetherFirstMessageOfAutoresponder($message)) {
                $columnUsedForReference = 'doc';
                return $columnUsedForReference;
            }
            return $columnUsedForReference;
        }

        private function whetherFirstMessageOfAutoresponder($message)
        {
            return $message->getPreviousMessageDayNumber() == -1;
        }

        private function __construct() {

        }

        public static function getProcessor() {
            if (empty(AutoresponderProcessor::$processor))
                AutoresponderProcessor::$processor = new AutoresponderProcessor();
            return AutoresponderProcessor::$processor;
        }



    }

$wpr_autoresponder_processor = AutoresponderProcessor::getProcessor();