# Changelog

## 1.1

- Correction : le sélecteur de lampes tournait sans fin. Un fichier `.htaccess`
  refusait l'accès au point d'entrée du plugin, et Apache répondait 403 sans que
  rien n'apparaisse côté Jeedom.
- L'icône du plugin s'affiche dans le menu : `plugin_info/.htaccess` autorise
  désormais les images.
- Nouvelle famille « Tous les équipements » dans le sélecteur : tout ce qui porte
  une commande d'action, pour les lampes déclarées en module ou en interrupteur.
  La commande qui allume et celle qui éteint s'y désignent à la main.
- Chaque ligne du sélecteur permet de voir et de changer les deux commandes
  retenues par le plugin.

## 1.0

Première version.

- Groupes de lampes : un groupe, un moment le soir, un moment le matin.
- Sélecteur de lampes : parcourt l'installation, range par pièce, distingue les
  lampes des prises et des équipements reconnus au nom, et allume la lampe pour
  la reconnaître.
- Déclenchement à heure fixe, ou par rapport au lever ou au coucher du soleil
  avec un décalage en minutes.
- Jours de la semaine, garde-fous « pas avant » et « pas après », décalage
  aléatoire pour la simulation de présence.
- Aperçu des trois prochaines occurrences de chaque moment.
- Commandes : prochain changement, allumer, éteindre, état, prochain soir,
  prochain matin, lever et coucher du soleil.
- Rattrapage des moments manqués, réglable.
- Ni démon, ni dépendance, ni appel réseau.
