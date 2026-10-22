<?php
namespace App\Livewire\Portal;
 
use Livewire\Component;
use App\Support\Notifications;
 
class NotificationBell extends Component
{
    public function markRead(string $id)
    {
        Notifications::markRead(session('tenant_id'), $id);
    }
 
    public function render()
    {
        $items = Notifications::unreadFor(session('tenant_id'), session('tenant_user_id'));
        return view('livewire.portal.notification-bell', ['items' => $items]);
    }
}
