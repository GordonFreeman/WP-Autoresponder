<?php

class BroadcastProcessor extends JavelinBackgroundProcess {

    public static function run(DateTime $time = null) {

        if (null == $time)
            $time = new DateTime();

        $broadcasts = new PendingBroadcasts($time);
        foreach ($broadcasts as $broadcast)
           $broadcast->deliver();
    }
}