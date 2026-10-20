# Changelog

## 0.2.1

- Le point d'entrée appelé par le démon n'accepte plus que la clé API du
  plugin : la clé personnelle d'un utilisateur Jeedom y donnait aussi accès.

## 0.2

- Les grandeurs par phase qui doublent un total (puissance, courant,
  puissances et courants apparents et réactifs) et la puissance du Wi-Fi ne
  sont plus historisées à leur création : sur un monophasé, les premières
  doublaient l'historique de la grandeur totale. La tension par phase, seule
  tension publiée par le P1, reste historisée. Les commandes déjà créées
  gardent le réglage choisi.

## 0.1

Première version.

- Relevé en réseau local des appareils HomeWizard Energy : compteur P1, prise
  connectée, compteurs kWh monophasés et triphasés (y compris SDM230 et SDM630),
  compteur d'eau et batterie.
- API locale v1, ou v2 après association par appui sur le bouton de l'appareil.
  Les commandes portent les noms de la v2 pour identifiant logique : passer de
  l'une à l'autre ne perd ni historique ni scénario.
- Découverte par balayage du sous-réseau, ou ajout par adresse IP. Un appareil
  déjà connu voit seulement son adresse mise à jour.
- Commandes créées au premier relevé, d'après ce que l'appareil publie
  réellement.
- Démon PHP sans dépendance pour relever jusqu'à chaque seconde, avec relevé par
  cron, une fois par minute, quand il est arrêté.
- Puissance totale et par phase, soutirée et injectée ; index d'import et
  d'export par tarif ; import, export et eau du jour et de la veille.
- Tarif capacitaire : demande moyenne, pointe du mois, pointe du mois précédent,
  pointe maximale et moyenne des pointes sur douze mois avec le plancher de
  2,5 kW, historique lu dans le télégramme du compteur.
- Qualité du réseau : tensions, courants, fréquence, puissances apparente et
  réactive, facteur de puissance, coupures, creux et surtensions.
- Compteurs de gaz, d'eau et de chaleur raccordés au P1.
- Actions : prise, verrou, identification, cloud, luminosité de la LED,
  redémarrage et stratégie de charge des batteries.
- Onglet Diagnostic montrant les dernières réponses brutes de l'appareil.
