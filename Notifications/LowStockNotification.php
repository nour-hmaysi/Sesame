<?php
namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LowStockNotification extends Notification
{
    use Queueable;

    protected $inventory;

    public function __construct($inventory)
    {
        $this->inventory = $inventory;
    }

    public function via($notifiable)
    {
        return ['mail'];
    }

    public function toMail($notifiable)
    {
        return (new MailMessage)
            ->line('Inventory Alert: Low Stock')
            ->line('The inventory item ' . $this->inventory->name . ' is running low.')
            ->action('View Inventory', url('/inventories/' . $this->inventory->id))
            ->line('Please replenish the stock as soon as possible.');
    }
}
