# Plugin Jeedom — Lampes Soir & Matin

Allumer les lampes le soir et les éteindre le matin, sans écrire un seul
scénario.

## Ce qu'il apporte

- **Les lampes trouvées pour vous.** Le plugin parcourt l'installation et ne
  propose que les équipements qui savent s'allumer et s'éteindre, rangés par
  pièce, les lampes avant les prises. Il n'y a ni liste de commandes à
  dépouiller, ni « On » et « Off » à choisir un par un : on coche un nom.
- **Un bouton pour reconnaître la bonne lampe.** Chaque ligne du sélecteur
  allume et éteint la lampe pour de vrai, et une pastille montre son état.
  C'est la façon la plus rapide de savoir laquelle est « Module 3 ».
- **Deux moments, pas davantage.** Un groupe, un moment le soir, un moment le
  matin. Ce qui s'allume le soir est exactement ce qui s'éteint le matin, par
  construction.
- **À l'heure ou au soleil.** Heure fixe, ou tant de minutes avant ou après le
  lever ou le coucher du soleil — calculé pour la position de votre
  installation, celle dont Jeedom se sert déjà dans ses scénarios.
- **Des garde-fous.** « Jamais avant 17:30 » pour les soirs d'hiver où le soleil
  se couche à 16 h 40, « jamais après 08:00 » pour les matins d'été.
- **Une simulation de présence en un champ.** Un décalage aléatoire de ± n
  minutes, tiré une fois par jour : l'heure annoncée est celle qui sera jouée.
- **Un aperçu qui ne ment pas.** Sous chaque moment, les trois prochaines fois,
  calculées par le code qui décidera de l'ordre au moment venu.

## Ce qu'il ne fait pas

Il ne surveille pas l'état réel des lampes : il envoie des ordres, il ne
corrige pas ce qu'un interrupteur mural a fait de son côté. La commande « État »
retient le dernier ordre envoyé par le plugin, pas l'état de l'ampoule.

Il ne règle ni l'intensité, ni la couleur, ni la température de couleur. Un
groupe allume et éteint, c'est tout ce qu'il promet.

Il n'a ni démon, ni dépendance, ni appel réseau : tout est en PHP, dans le cron
du cœur.

## Prérequis

La position de l'installation doit être renseignée dans Réglages → Système →
Configuration → Général. Sans latitude ni longitude, les heures de lever et de
coucher du soleil sont celles du point zéro, au large du golfe de Guinée — et
rien ne le signale à part ce plugin.

## Installation

Plugins → Gestion des plugins → Ajouter → Github, puis :

| Champ | Valeur |
|---|---|
| Utilisateur | `replicatorbe` |
| Dépôt | `jeedom-plugin-lampesoirmatinbe` |
| Branche | `master` (stable) ou `beta` |

## Branches

- **`master`** — version stable. Tout commit poussé ici est proposé en mise à
  jour aux utilisateurs, Jeedom identifiant la version par le SHA du dernier
  commit de la branche.
- **`beta`** — développement. C'est la branche par défaut du dépôt.

## Architecture

```
cron du cœur (chaque minute)
        │
        ├─ pour chaque groupe actif, pour chaque moment activé :
        │     lampesoirmatinbeSun::dueOccurrence()
        │         heure fixe | soleil ± n min → garde-fous → tirage du jour
        │         passé depuis moins que le rattrapage, pas déjà joué ?
        │                                │
        │                       oui ─────┴──► ordre à chaque lampe du groupe
        │                                     (commande d'action du plugin tiers)
        │
        └─ mise à jour des commandes d'information
              « prochain changement », heures du soleil
```

Le calcul est dans `lampesoirmatinbeSun`, qui ne connaît pas Jeedom : c'est ce
qui permet de l'éprouver sur une année entière hors ligne (`php tests/run.php`).
La reconnaissance des lampes est dans `lampesoirmatinbeLamps`, dont la partie
décisive — « cet équipement est-il une lampe ? » — ne connaît pas Jeedom non
plus.

## Contrôles

```bash
php tests/run.php            # 70 contrôles hors ligne, sans Jeedom
php tests/check-classes.php  # les pièges du cœur, par réflexion
```

## Documentation

- [Français](docs/fr_FR/index.md)
- [English](docs/en_US/index.md)

## Licence

AGPL v3. Voir [LICENSE](LICENSE).
