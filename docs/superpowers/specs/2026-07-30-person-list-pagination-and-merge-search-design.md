# Paginação de pessoas e busca para mesclagem

## Problema

O endpoint administrativo de pessoas limita consultas nomeadas às 20 primeiras pessoas. A edição de pessoa carrega esse resultado uma única vez em um `Select`, portanto destinos posteriores não podem ser escolhidos para mesclagem.

Na listagem sem filtro, o endpoint retorna até 200 entidades e a normalização chama `count($person->getFaces())` e o fallback de avatar baseado em `getFaces()`. Essas operações inicializam coleções completas de rostos no Doctrine e podem esgotar os 512 MB disponíveis.

## Solução

O endpoint `GET /api/admin/people` passa a aceitar `page` e `perPage` e retorna o contrato paginado já usado no projeto:

```json
{
  "data": [],
  "meta": {
    "page": 1,
    "perPage": 50,
    "total": 0
  }
}
```

`scope` e `q` continuam disponíveis. `page` será no mínimo 1 e `perPage` ficará limitado a um intervalo seguro. A busca usada por seletores solicitará páginas pequenas de até 20 resultados.

O repositório buscará somente a página solicitada. A quantidade de rostos e o caminho de avatar serão calculados em consultas agregadas para os IDs dessa página. A normalização da listagem receberá esses valores pré-calculados e não acessará `Person::getFaces()`. A resposta detalhada de uma única pessoa continuará carregando seus rostos normalmente.

## Interface administrativa

A tela de pessoas exibirá 50 registros por página e controles para avançar e voltar. A página atual fará parte da URL junto com `scope` e `q`; alterar filtro ou busca voltará para a página 1.

Na edição de pessoa, o `Select` estático será substituído por uma busca por nome. Cada alteração consultará pessoas nomeadas no servidor, mostrará até 20 correspondências e excluirá a própria pessoa dos resultados. Qualquer pessoa nomeada poderá ser encontrada sem carregar a lista completa.

A busca de pessoas na edição de fotos reutilizará o mesmo contrato paginado, mantendo o comportamento atual de exibir uma pequena lista de correspondências.

## Erros e concorrência

Falhas na busca de destinos não apagarão os dados da pessoa editada; a interface mostrará uma mensagem de erro e permitirá nova tentativa. Respostas antigas de buscas rápidas não poderão substituir resultados de uma consulta mais recente.

Se a página solicitada ficar vazia após exclusões, a tela continuará exibindo o estado vazio e permitirá voltar para a página anterior.

## Testes

- API: contrato e metadados de paginação, filtros, busca sem acentos e limites de página.
- API: mais de 20 pessoas nomeadas são encontráveis por `q`.
- API/repositório: a listagem usa contagens e avatares agregados sem inicializar coleções completas de rostos.
- Web: navegação entre páginas preserva filtros e busca.
- Web: alterar filtro ou busca reinicia a página.
- Web: busca de destino de mesclagem consulta o servidor, ignora a própria pessoa e seleciona um resultado posterior às primeiras 20.
- Web: resultados atrasados não sobrescrevem a busca mais recente.
