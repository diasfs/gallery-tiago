# Busca de pessoas sem distinção de acentos

## Objetivo

Fazer as buscas pública e administrativa de pessoas tratarem letras acentuadas e não acentuadas como equivalentes. Por exemplo, `fabio` deve encontrar uma pessoa chamada `Fábio`.

## Design

A normalização ficará na API, dentro de `PersonRepository`, porque é o banco que filtra e limita os resultados enviados aos seletores. Os métodos `search()` e `searchNamedPublic()` usarão `LOWER(UNACCENT(...))` sobre o nome armazenado e `SearchText::likePattern()` sobre a consulta, seguindo o padrão já usado nas buscas de álbuns, fotos e locais.

`findOneNamedByName()` continuará fazendo comparação exata sem remoção de acentos. Assim, esta correção não altera as regras de criação ou deduplicação de pessoas.

## Testes

Um teste da API pública comprovará que `GET /api/people?q=fabio` retorna `Fábio` quando a pessoa aparece em álbum público. Um teste da API administrativa comprovará o mesmo comportamento em `GET /api/admin/people?q=fabio`.

Os testes existentes continuarão validando que a busca pública não expõe pessoas presentes apenas em álbuns privados.
