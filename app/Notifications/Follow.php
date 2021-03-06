<?php

/*
 * +----------------------------------------------------------------------+
 * |                          ThinkSNS Plus                               |
 * +----------------------------------------------------------------------+
 * | Copyright (c) 2016-Present ZhiYiChuangXiang Technology Co., Ltd.     |
 * +----------------------------------------------------------------------+
 * | This source file is subject to enterprise private license, that is   |
 * | bundled with this package in the file LICENSE, and is available      |
 * | through the world-wide-web at the following url:                     |
 * | https://github.com/slimkit/plus/blob/master/LICENSE                  |
 * +----------------------------------------------------------------------+
 * | Author: Slim Kit Group <master@zhiyicx.com>                          |
 * | Homepage: www.thinksns.com                                           |
 * +----------------------------------------------------------------------+
 */

namespace Zhiyi\Plus\Notifications;

use Illuminate\Bus\Queueable;
use Zhiyi\Plus\Models\User as UserModel;
use Illuminate\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Medz\Laravel\Notifications\JPush\Message as JPushMessage;
use function Zhiyi\Plus\setting;

class Follow extends Notification implements ShouldQueue
{
    use Queueable;

    protected $sender;
    /**
     * @var \Zhiyi\Plus\Support\any|\Zhiyi\Plus\Support\Setting
     */
    protected static $jpushConfig;

    /**
     * Create a new notification instance.
     *
     * @param  UserModel  $sender
     */
    public function __construct(UserModel $sender)
    {
        $this->sender = $sender;
        self::$jpushConfig = setting('user', 'vendor:jpush', [
            'app_key' => '',
            'master_secret' => '',
            'apns_production' => false,
            'switch' => false
        ]);
    }

    /**
     * Get the notification's delivery channels.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function via($notifiable)
    {
        if ($notifiable->id === $this->sender->id) {
            return [];
        }

        return self::$jpushConfig['switch'] ? ['database', 'jpush'] : ['database'];
    }

    /**
     * Get the JPush representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return \Medz\Laravel\Notifications\JPush\Message
     * @throws \Exception
     */
    public function toJpush($notifiable): JPushMessage
    {
        $alert = sprintf('%s关注了你，去看看吧', $this->sender->name);
        $extras = [
            'tag' => 'notification:follow',
        ];

        $payload = new JPushMessage;
        $payload->setMessage($alert, [
            'content_type' => $extras['tag'],
            'extras' => $extras,
        ]);
        $payload->setNotification(JPushMessage::IOS, $alert, [
            'content-available' => false,
            'thread-id' => $extras['tag'],
            'extras' => $extras,
        ]);
        $payload->setNotification(JPushMessage::ANDROID, $alert, [
            'extras' => $extras,
        ]);
        $payload->setOptions([
            'apns_production' => self::$jpushConfig['apns_production'],
        ]);

        return $payload;
    }

    /**
     * Get the array representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function toArray($notifiable)
    {
        return [
            'id' => $this->sender->id,
            'name' => $this->sender->name,
        ];
    }
}
