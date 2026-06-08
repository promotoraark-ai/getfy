# Corrigir erro 500 após atualizar (hospedagem compartilhada)

Depois de atualizar o Getfy, é comum aparecer **erro 500** porque o banco de dados ainda não recebeu as **migrations** (tabelas/colunas novas da versão).

Em hospedagem compartilhada, muitas vezes não há terminal/SSH. Nesse caso, dá para corrigir **editando o `.env`**.

---

## Passo a passo

1. **Acesse o gerenciador de arquivos** da hospedagem (cPanel, Plesk, DirectAdmin, etc.).
2. Abra a pasta raiz do Getfy (onde ficam `artisan`, `app`, `public`…).
3. Edite o arquivo **`.env`**
4. Localize a linha:

   ```env
   APP_AUTO_MIGRATE=false
   ```

5. Altere para:

   ```env
   APP_AUTO_MIGRATE=true
   ```

6. **Salve** o arquivo.
7. Acesse o site no navegador (painel ou página inicial).  
   Na primeira visita, o sistema tenta rodar as migrations automaticamente e recarregar a página.
8. Se voltar ao normal, **recomendado** voltar a linha para `false`:

   ```env
   APP_AUTO_MIGRATE=false
   ```

---

## O que essa opção faz?

Com `APP_AUTO_MIGRATE=true`, quando o erro for por **tabela ou coluna faltando no banco**, o Getfy executa `php artisan migrate --force` sozinho e pede para recarregar.

> **Atenção:** isso resolve erros de **banco desatualizado**. Se o 500 for por outro motivo (`.env` incompleto, permissão de pasta, `APP_KEY` vazio, etc.), será preciso ver o log ou o suporte da hospedagem.

