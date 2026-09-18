# Lampes Soir & Matin

Allumer les lampes le soir et les éteindre le matin, sans écrire un seul
scénario.

Le plugin ne pilote pas de matériel : il commande les lampes que vos autres
plugins ont créées — Zigbee, Z-Wave, Hue, MQTT, prises Wi-Fi, modules anciens.
Tout ce qui sait s'allumer et s'éteindre dans Jeedom peut entrer dans un groupe.

## Avant de commencer

Renseignez la position de votre installation dans **Réglages → Système →
Configuration → Général**. Sans latitude ni longitude, les heures de lever et de
coucher du soleil sont fausses toute l'année, et rien d'autre que ce plugin ne
vous le dira.

C'est la même position que celle dont Jeedom se sert pour `#sunrise#` et
`#sunset#` dans les scénarios : les heures du plugin et celles de vos scénarios
ne peuvent donc pas diverger.

## Créer un groupe

Un **groupe** rassemble les lampes qu'on allume et qu'on éteint ensemble : le
salon, la façade, les chambres d'enfants. Un groupe a deux moments, le soir et
le matin, et c'est tout ce qu'il faut régler.

1. **Plugins → Automatisation → Lampes Soir & Matin → Ajouter un groupe.**
   Donnez-lui le nom de ce qu'il éclaire.
2. **Choisir les lampes.** Le bouton ouvre le sélecteur.
3. **Onglet Programmation.** Réglez le soir, réglez le matin.
4. **Sauvegarder.** Il n'y a rien d'autre à faire.

## Le sélecteur de lampes

Le sélecteur parcourt l'installation et ne montre que ce qui peut s'allumer et
s'éteindre, rangé par pièce. Trois familles, dont deux affichées d'emblée :

| Famille | Ce que c'est |
|---|---|
| **Lampes** | L'équipement porte les types génériques *Lumière* du cœur. Aucun doute. |
| **Prises** | Une prise commandée. C'en est peut-être une qui porte une lampe : à vous de le dire. |
| **Autres** | Ni l'un ni l'autre, mais deux commandes qui s'appellent « On » et « Off ». Beaucoup de protocoles anciens ne remplissent pas les types génériques ; sans ce filet, leurs lampes seraient introuvables. |
| **Tous les équipements** | Tout ce qui porte une commande d'action, y compris ce dont le plugin ne sait rien dire. À n'ouvrir que si votre lampe n'apparaît nulle part ailleurs : ici, c'est vous qui désignez la commande qui allume et celle qui éteint. |

Le bouton **⚙** de chaque ligne montre les deux commandes retenues et permet de
les changer : une prise dont le « On » et le « Off » sont inversés, un module qui
appelle son allumage « Impulsion », un équipement à trois relais dont un seul
porte la lampe — tout cela se corrige là, sans quitter le sélecteur.

Chaque ligne porte deux boutons qui **allument et éteignent la lampe pour de
vrai**, et une pastille qui montre son état quand l'équipement le publie. C'est
la façon la plus rapide de savoir laquelle des trois lampes s'appelle
« Module 3 » — sans quitter la page, sans ouvrir un autre onglet.

Vous cochez des **équipements**, pas des commandes : le plugin retient lui-même
laquelle allume et laquelle éteint. Un équipement qui ne sait que basculer —
beaucoup d'interrupteurs muraux — est accepté, et sa bascule sert aux deux
ordres ; c'est le seul cas où le plugin peut se tromper d'état, si la lampe a été
touchée à la main entre-temps.

Le bouton **Tout cocher** d'une pièce répond au geste le plus fréquent :
« toutes les lampes du salon » est une intention, pas six décisions.

## Régler un moment

Le soir et le matin se règlent exactement de la même façon.

**Faire** — allumer ou éteindre. Le soir allume et le matin éteint par défaut,
mais rien n'oblige à s'y tenir : un groupe « lampes du matin » allume au lever et
éteint une heure après.

**Quand** — trois possibilités :

- **À une heure fixe** : 20:00, toute l'année.
- **Par rapport au coucher du soleil** : tant de minutes avant ou après.
- **Par rapport au lever du soleil** : idem.

**Jours** — les jours de la semaine concernés. Aucun jour coché veut dire
*jamais*, pas *tous les jours* : c'est ce qu'on attend quand on vient de tout
décocher pour suspendre un moment.

**Garde-fous** — « pas avant » et « pas après ». À Bruxelles, le soleil se couche
à 16 h 40 le 21 décembre et à 22 h 00 le 21 juin : un allumage calé sur le
coucher varierait de cinq heures dans l'année. « Pas avant 17:30 » ramène les
soirs d'hiver à 17:30 — le garde-fou **ramène**, il n'annule pas. Laisser vide
pour suivre le soleil toute l'année.

**Décalage aléatoire** — simulation de présence. Le moment est avancé ou retardé
au hasard dans cette limite. Le tirage est fait **une fois par jour** : l'heure
annoncée dans l'aperçu est bien celle qui sera jouée, et deux groupes réglés de
la même façon ne s'allument pas à la même seconde.

**Prochaines fois** — sous chaque moment, les trois prochaines occurrences,
calculées par le code même qui décidera de l'ordre au moment venu. Si un moment
n'est jamais annoncé, c'est qu'il ne se déclenchera jamais : moment désactivé,
aucun jour coché, ou position manquante.

## Les commandes créées

| Commande | Type | Rôle |
|---|---|---|
| **Prochain changement** | info | « Allumage aujourd'hui 20:42 ». Visible sur le tableau de bord. |
| **Allumer** / **Éteindre** | action | Agit sur tout le groupe, à la main ou depuis un scénario. |
| **État** | info binaire | Le dernier ordre envoyé par le plugin. Historisé. |
| **Prochain soir** / **Prochain matin** | info | Les deux rendez-vous séparément. |
| **Lever du soleil** / **Coucher du soleil** | info | Les heures du jour, utiles en scénario. |

Seules les trois premières sont visibles à la création ; les autres sont créées
masquées, à réafficher si vous en avez l'usage.

**L'« État » est celui du plugin, pas celui de l'ampoule.** Le plugin envoie des
ordres, il ne surveille pas ce qu'un interrupteur mural fait de son côté.

## Le rattrapage

Le cron passe chaque minute. Si la box était éteinte à l'heure dite, le moment
est encore joué pendant le **rattrapage** — 15 minutes par défaut, réglable dans
la configuration du plugin. Passé ce délai il est abandonné : allumer les lampes
du soir à trois heures du matin ne rend service à personne.

Un moment n'est joué qu'une fois par jour, même si le cron repasse soixante fois
pendant le rattrapage.

## Questions fréquentes

**Une lampe ne s'allume plus.** Ouvrez le groupe : une lampe supprimée de Jeedom
porte l'étiquette « Équipement supprimé », une lampe désactivée porte la sienne.
Un échec d'ordre produit aussi un message au centre de messages de Jeedom.

**J'ai renommé une lampe, dois-je la re-choisir ?** Non. Les lampes sont
enregistrées par leur identifiant ; le nom affiché est rafraîchi à chaque
ouverture de la page.

**Puis-je mettre la même lampe dans deux groupes ?** Oui, mais les deux groupes
lui enverront leurs ordres : le dernier arrivé gagne.

**Un groupe peut-il contenir un autre groupe ?** Non. Le sélecteur ne se propose
pas lui-même, un groupe qui se contiendrait s'appellerait sans fin.

**Où est le journal ?** Analyse → Logs → `lampesoirmatinbe`. Chaque ordre joué y
laisse une ligne avec l'heure prévue et le réglage qui l'a produite.
