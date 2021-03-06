<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class UpdateRound implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;
    public $round;
    public $action;
    public $minutes;
    public $boss_hp;

    /**
     * Create a new event instance.
     *
     * @return void
     */
    public function __construct($round, $action, $minutes, $boss_hp)
    {
        $this->round = $round;
        $this->action = $action;
        $this->minutes = $minutes;
        $this->boss_hp = $boss_hp;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return \Illuminate\Broadcasting\Channel|array
     */
    public function broadcastOn()
    {
        return new Channel('roundChannel');
    }

    public function broadcastAs()
    {
        return 'update';
    }
}
