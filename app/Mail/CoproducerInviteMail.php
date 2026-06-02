<?php

namespace App\Mail;

use App\Models\Product;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class CoproducerInviteMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Product $product,
        public string $inviteUrl,
        public float $commissionPercent,
    ) {}

    public function build()
    {
        $subject = 'Convite de co-produção — '.$this->product->name;

        $html = '<p>Você foi convidado(a) para ser co-produtor(a) do produto <strong>'.e($this->product->name).'</strong>.</p>'
            .'<p>Comissão: <strong>'.e(number_format($this->commissionPercent, 2, ',', '.')).'%</strong> sobre o valor líquido das vendas elegíveis.</p>'
            .'<p><a href="'.e($this->inviteUrl).'">Ver convite e aceitar</a></p>';

        return $this->subject($subject)->html($html);
    }
}
