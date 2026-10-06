<?php
declare(strict_types=1);

namespace FudgeDonuts;

final class EmailDeliveryService
{
    public function __construct(
        private readonly string $transport,
        private readonly string $from,
        private readonly string $fromName='Fudge Donuts',
        private readonly string $smtpHost='',
        private readonly int $smtpPort=587,
        private readonly string $smtpUsername='',
        private readonly string $smtpPassword='',
        private readonly string $smtpEncryption='tls'
    ) {}

    public function send(array $message): void
    {
        $to=(string)$message['recipient'];$subject=$this->cleanHeader((string)$message['subject']);
        if(!filter_var($to,FILTER_VALIDATE_EMAIL) || !filter_var($this->from,FILTER_VALIDATE_EMAIL)) throw new \InvalidArgumentException('Invalid email address.');
        $mime=$this->buildMime($message);
        if($this->transport==='log'){fwrite(STDOUT,"[email] {$to} | {$subject}\n");return;}
        if($this->transport==='mail'){
            $headers="From: ".$this->cleanHeader($this->fromName)." <{$this->from}>\r\nMIME-Version: 1.0\r\n".$mime['headers'];
            if(!mail($to,$subject,$mime['body'],$headers)) throw new \RuntimeException('mail() returned false');
            return;
        }
        if($this->transport==='smtp'){$this->sendSmtp($to,$subject,$mime);return;}
        throw new \RuntimeException('Unsupported MAIL_TRANSPORT.');
    }

    public function buildMime(array $message): array
    {
        $text=(string)($message['body']??'');$html=(string)($message['html_body']??'');
        if($html==='') return ['headers'=>'Content-Type: text/plain; charset=UTF-8','body'=>$text];
        $boundary='fd_'.bin2hex(random_bytes(12));
        $body="--{$boundary}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n{$text}\r\n";
        $body.="--{$boundary}\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n{$html}\r\n--{$boundary}--\r\n";
        return ['headers'=>'Content-Type: multipart/alternative; boundary="'.$boundary.'"','body'=>$body];
    }

    private function sendSmtp(string $to,string $subject,array $mime): void
    {
        if($this->smtpHost==='') throw new \RuntimeException('SMTP host is not configured.');
        $scheme=$this->smtpEncryption==='ssl'?'ssl://':'tcp://';
        $socket=@stream_socket_client($scheme.$this->smtpHost.':'.$this->smtpPort,$errno,$errstr,20,STREAM_CLIENT_CONNECT);
        if(!$socket) throw new \RuntimeException('SMTP connection failed: '.$errstr);
        stream_set_timeout($socket,20);
        try{
            $this->expect($socket,[220]);$this->command($socket,'EHLO fudge-donuts.local',[250]);
            if($this->smtpEncryption==='tls'){
                $this->command($socket,'STARTTLS',[220]);
                if(!stream_socket_enable_crypto($socket,true,STREAM_CRYPTO_METHOD_TLS_CLIENT)) throw new \RuntimeException('SMTP TLS negotiation failed.');
                $this->command($socket,'EHLO fudge-donuts.local',[250]);
            }
            if($this->smtpUsername!==''){
                $this->command($socket,'AUTH LOGIN',[334]);
                $this->command($socket,base64_encode($this->smtpUsername),[334]);
                $this->command($socket,base64_encode($this->smtpPassword),[235]);
            }
            $this->command($socket,'MAIL FROM:<'.$this->from.'>',[250]);
            $this->command($socket,'RCPT TO:<'.$to.'>',[250,251]);
            $this->command($socket,'DATA',[354]);
            $headers=[
                'From: '.$this->cleanHeader($this->fromName).' <'.$this->from.'>',
                'To: <'.$to.'>',
                'Subject: '.$subject,
                'MIME-Version: 1.0',
                $mime['headers'],
            ];
            $data=implode("\r\n",$headers)."\r\n\r\n".$mime['body'];
            $data=preg_replace('/(?m)^\./','..',$data);
            fwrite($socket,$data."\r\n.\r\n");$this->expect($socket,[250]);
            $this->command($socket,'QUIT',[221]);
        }finally{fclose($socket);}
    }

    private function command($socket,string $command,array $expected): void
    {
        fwrite($socket,$command."\r\n");$this->expect($socket,$expected);
    }

    private function expect($socket,array $expected): void
    {
        $line='';$code=0;
        do{
            $chunk=fgets($socket,515);
            if($chunk===false) throw new \RuntimeException('SMTP connection closed unexpectedly.');
            $line=$chunk;$code=(int)substr($chunk,0,3);
        }while(isset($chunk[3]) && $chunk[3]==='-');
        if(!in_array($code,$expected,true)) throw new \RuntimeException('SMTP error '.$code.': '.trim($line));
    }

    private function cleanHeader(string $value): string
    {
        return trim(str_replace(["\r","\n"],' ',$value));
    }
}
