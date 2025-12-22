<?php
namespace System\Http;

use System\Core\Request as CoreRequest;

final class Request
{
    protected CoreRequest $req;

    public function __construct(?\Psr\Http\Message\ServerRequestInterface $psr = null)
    {
        $this->req = $psr ? new CoreRequest($psr) : CoreRequest::fromGlobals();
    }

    // delegate methods you use in your app
    public function getMethod(): string { return $this->req->getMethod(); }
    public function getPath(int $i = -1) { return $this->req->getPath($i); }
    public function getBasePath(): string{return $this->req->getBasePath();} 
    public function getQuery(?string $k = null, $d = null) { return $this->req->getQuery($k,$d); }
    public function input(string $key, $d = null) { return $this->req->input($key,$d); }
    public function jsonInput(string $key, $d = null) { return $this->req->jsonInput($key,$d); }
    public function allFiles(): array { return $this->req->allFiles(); }
    public function inputFileBase64(string $n, $d = null) { return $this->req->inputFileBase64($n,$d); }
    public function cache(?string $p = null) { return $this->req->cache($p); }
    public function validate(array $r, array $m = [], array $s = []) { return $this->req->validate($r,$m,$s); }
    public function set($a,$v): void { $this->req->set($a,$v); }
    public function attr($n) { return $this->req->attr($n); }
    public function ip() { return $this->req->ip(); }
    public function getPsrRequest() { return $this->req->getPsrRequest(); }
    public function getHeader(string $name){return $this->req->getHeader($name);}
    public function getMedia(int $media_id){return $this->req->getMedia($media_id);}
    public function getAll(): array { return $this->req->all(); }
    public function inputMedia(string $name, ?string $default = null, array $options = []) { return $this->req->inputMedia($name,$default,$options);}
}