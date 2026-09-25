# Plugin Jeedom — HomeWizard

Relève en réseau local les appareils **HomeWizard Energy** — compteur P1, prise
connectée, compteurs kWh, compteur d'eau, batterie — sans passer par le cloud du
constructeur et sans compte.

Le plugin interroge l'API locale de chaque appareil, en **v1** ou en **v2**
après association par appui sur le bouton, et crée les commandes que l'appareil
publie réellement. Un démon PHP relève jusqu'à chaque seconde ; s'il est arrêté,
le cron de Jeedom prend le relais, une fois par minute. Aucune dépendance à
installer.

## Ce qu'il remonte

- **Compteur P1** : puissance totale et par phase, tensions, courants, index
  d'import et d'export par tarif, tarif actif, gaz, eau et chaleur des compteurs
  raccordés, coupures, creux et surtensions.
- **Tarif capacitaire** : demande moyenne, pointe du mois, historique des
  pointes lu dans le télégramme du compteur belge, et moyenne sur douze mois avec
  le plancher de 2,5 kW.
- **Prise, compteurs kWh, compteur d'eau, batterie** : puissance, énergie,
  état, débit, état de charge — et les actions qui vont avec : prise, verrou,
  LED, cloud, redémarrage, stratégie de charge des batteries.
- Énergie et eau **du jour et de la veille**, calculées à partir des index.

Les commandes portent les noms de la v2 pour identifiant logique : passer un
appareil de v1 à v2 ne perd ni historique ni scénario.

## Installation

Plugins → Gestion des plugins → Ajouter → Github, puis
`replicatorbe / jeedom-plugin-homewizardbe`, branche `master` pour le canal
stable ou `beta` pour la version de développement.

Dans l'application HomeWizard Energy, activez l'API locale de chaque appareil
(Réglages → Compteurs → l'appareil → API locale). Ensuite : Plugins → Énergie →
HomeWizard → **Rechercher sur le réseau**.

La documentation complète est dans [`docs/fr_FR/index.md`](docs/fr_FR/index.md).

## Développement

Les fixtures de `tests/fixtures/` sont des réponses réelles d'appareils
HomeWizard, pour rejouer le décodage sans matériel. Toute valeur douteuse
rencontrée en production mérite d'y laisser un fichier.

## Licence

AGPL v3. Ce plugin n'est pas affilié à HomeWizard.
