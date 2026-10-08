# prototipo-assinador

Protótipo de laboratório para **assinar PDFs digitalmente (PAdES)** com certificado A1 (`.pfx`/`.p12`), usando a biblioteca [`tecnickcom/tc-lib-pdf-sign`](https://github.com/tecnickcom/tc-lib-pdf-sign).

A assinatura é feita como **revisão incremental**: o PDF original não é reescrito, apenas acrescentamos novos objetos ao final, preservando assinaturas anteriores.

## Funcionalidades

- Assinatura de PDF existente com certificado `.pfx`/`.p12` (lido em memória, sem gravar a chave em disco).
- Perfis PAdES: `pades-b-b` (básico) e `pades-b-t` (com carimbo de tempo via TSA `freetsa.org`).
- Múltiplas assinaturas em sequência (até 4 assinantes por envio na interface web).
- Assinatura visível posicionada na página ou invisível.
- Interface web com validação do certificado (CN, CPF/CNPJ, emissor, validade) antes de assinar.
- Download e visualização do PDF assinado.
- Script de linha de comando para testes rápidos.

## Requisitos

- PHP 8.1+ com a extensão `openssl`
- [Composer](https://getcomposer.org/)

## Instalação

```bash
composer install
```

## Uso

### Interface web

```bash
php -S localhost:8000 -t public
```

Acesse <http://localhost:8000>, envie o PDF, informe o(s) certificado(s) e senha(s), escolha o perfil e, se quiser, a posição da assinatura visível. Os PDFs assinados ficam em `out/` (nome `assinado-<8 hex>.pdf`).

### Linha de comando

```bash
php sign.php entrada.pdf saida.pdf [perfil] [pfx] [senha]
```

Exemplo com o PDF de amostra e o certificado de teste (veja abaixo):

```bash
mkdir -p out
php sign.php samples/exemplo.pdf out/assinado.pdf
```

Padrões: perfil `pades-b-b`, PFX `certs/teste.pfx`, senha `teste123`.

## Estrutura

```
public/index.php     Interface web (upload, validação do certificado, download)
src/PdfSigner.php    Classe Lab\PdfSigner: gera a revisão incremental e o CMS
sign.php             Script CLI simplificado
samples/exemplo.pdf  PDF de exemplo
certs/               Certificados de teste (ignorado pelo git)
out/                 PDFs assinados (ignorado pelo git)
```

## Certificados de teste

`certs/` não é versionado. Para testes locais, gere uma CA e um certificado de folha autoassinados e exporte para PFX, por exemplo:

```bash
mkdir -p certs && cd certs
openssl req -x509 -newkey rsa:2048 -nodes -keyout ca.key -out ca.crt -subj "/CN=CA Teste" -days 365
openssl req -newkey rsa:2048 -nodes -keyout leaf.key -out leaf.csr -subj "/CN=EMPRESA TESTE LTDA:12345678000199"
openssl x509 -req -in leaf.csr -CA ca.crt -CAkey ca.key -CAcreateserial -out leaf.crt -days 365
openssl pkcs12 -export -inkey leaf.key -in leaf.crt -certfile ca.crt -out teste.pfx -passout pass:teste123
```

Certificados autoassinados não são reconhecidos como confiáveis por leitores de PDF (o Adobe, por exemplo, mostrará a assinatura como "não confiável"). Para validade jurídica é preciso um certificado ICP-Brasil.

## Limitações do protótipo

- Só PDFs com **xref clássico** (sem object streams / xref streams).
- Não há suporte a `/AcroForm` ou `/Annots` indiretos, nem a páginas rotacionadas.
- O espaço reservado para a assinatura (CMS) é fixo em 16 KB; cadeias de certificados muito grandes podem estourar.
- O carimbo de tempo usa a TSA pública `freetsa.org` (requer acesso à internet e não tem valor legal).
- Sem autenticação, controle de acesso ou limpeza de `out/`. **Não exponha na internet.**

## Segurança

- Nunca versione certificados, chaves privadas ou senhas (`certs/` está no `.gitignore`).
- A senha `teste123` do `sign.php` serve apenas para o certificado de laboratório.
- Os certificados enviados pela interface web são lidos em memória e não são gravados.
