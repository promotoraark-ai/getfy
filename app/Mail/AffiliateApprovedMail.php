<?php

namespace App\Mail;

use App\Models\Product;
use App\Models\ProductAffiliate;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class AffiliateApprovedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Product $product,
        public ProductAffiliate $affiliate,
        public string $affiliateLink,
    ) {}

    public function build()
    {
        $subject = 'Afiliação aprovada — '.$this->product->name;

        $html = '<p>Sua afiliação ao produto <strong>'.e($this->product->name).'</strong> foi aprovada.</p>'
            .'<p>Seu link de divulgação:<br/><a href="'.e($this->affiliateLink).'">'.e($this->affiliateLink).'</a></p>'
            .'<p>Código: <strong>'.e($this->affiliate->affiliate_code).'</strong></p>';

        return $this->subject($subject)->html($html);
    }
}
