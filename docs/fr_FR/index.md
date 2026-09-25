# Plugin HomeWizard

Relève en réseau local les appareils **HomeWizard Energy** — compteur P1, prise
connectée, compteurs kWh, compteur d'eau, batterie — sans passer par le cloud
du constructeur, sans compte et sans l'application une fois l'appareil installé.

Le plugin interroge l'API locale de chaque appareil, en v1 ou en v2, et crée les
commandes que l'appareil publie réellement. Un démon PHP permet de descendre
sous la minute ; s'il est arrêté, le cron de Jeedom prend le relais, une fois par
minute. Aucune dépendance à installer : PHP et l'extension curl, déjà exigés par
Jeedom, suffisent.

## Appareils pris en charge

| Modèle | Nom | API | Ce qu'il remonte |
|---|---|---|---|
| `HWE-P1` | P1 Meter | v1, v2 | Puissance totale et par phase, tensions, courants, index d'import et d'export par tarif, tarif actif, demande moyenne et pointe du mois, historique des pointes (tarif capacitaire), compteurs de gaz, d'eau et de chaleur raccordés, coupures, creux et surtensions |
| `HWE-SKT` | Energy Socket | v1 | Puissance, tension, courant, fréquence, énergie, état de la prise, verrou, luminosité de l'anneau |
| `HWE-KWH1` | kWh Meter 1 phase | v1, v2 | Puissance, tension, courant, fréquence, puissances apparente et réactive, facteur de puissance, index d'import et d'export |
| `HWE-KWH3` | kWh Meter 3 phases | v1, v2 | Les mêmes grandeurs, par phase |
| `SDM230-wifi` | kWh Meter 1 phase (SDM230) | v1 | Comme le `HWE-KWH1` |
| `SDM630-wifi` | kWh Meter 3 phases (SDM630) | v1 | Comme le `HWE-KWH3` |
| `HWE-WTR` | Watermeter | v1 | Débit instantané, index total, eau du jour et de la veille |
| `HWE-BAT` | Plug-In Battery | v2 uniquement | État de charge, cycles, puissance, tension, courant, énergie chargée et déchargée |
| `HWE-DSP` | Energy Display | — | Reconnu et identifié ; les commandes dépendent de ce qu'il publie |

La colonne « Ce qu'il remonte » décrit ce que l'appareil sait publier. Le plugin
ne suppose rien : un P1 monophasé n'aura pas de phase 3, un compteur sans gaz
n'aura pas de gaz (voir « Des commandes créées au premier relevé »).

## Prérequis

- Un ou plusieurs appareils HomeWizard Energy sur le même réseau que Jeedom.
- **L'API locale activée** pour chaque appareil en v1, dans l'application
  HomeWizard Energy : **Réglages → Compteurs → l'appareil → API locale**. Sans
  cela l'appareil répond « API not enabled » et le plugin ne lit rien. La
  batterie fait exception : elle ne parle que la v2, qui s'active par
  association (voir plus bas).

Réservez l'adresse IP de chaque appareil dans votre routeur. Si elle change, le
plugin ne trouve plus l'appareil ; relancer la recherche met alors l'adresse à
jour sans créer de doublon.

## Installation

1. Installez le plugin, puis activez-le.
2. Laissez le démon en gestion automatique : il se lance dès qu'un appareil
   actif a une adresse IP.
3. Ouvrez **Plugins → Énergie → HomeWizard**.

Rien d'autre à installer : le plugin n'a pas de dépendance.

## Découverte des appareils

### Rechercher sur le réseau

Le bouton **Rechercher sur le réseau** propose de saisir un sous-réseau, par
exemple `192.168.1.0/24`. Laissé vide, c'est celui de Jeedom qui est parcouru.

Le plugin pose alors la question `http://<adresse>/api` aux 254 adresses du
sous-réseau, en parallèle : l'ensemble tient en deux ou trois secondes. Il
n'utilise pas mDNS, et c'est voulu — le multicast ne traverse ni Docker ni bien
des VLAN, alors qu'une question HTTP adressée à chaque machine passe partout.

La liste des appareils qui ont répondu s'affiche, avec leur modèle, leur adresse,
leur numéro de série et leur micrologiciel. Cochez ceux à créer. Un appareil
déjà connu — reconnu à son numéro de série — n'est pas recréé : seule son adresse
est mise à jour.

Deux limites à connaître :

- seul le dernier octet est parcouru : `192.168.1.0/24` et `192.168.1.0/16`
  interrogent tous deux `192.168.1.1` à `192.168.1.254` ;
- la recherche passe par l'API v1. Un appareil dont la v1 est désactivée, et la
  batterie qui n'a que la v2, ne se trouvent pas ainsi : ajoutez-les par leur
  adresse IP.

### Ajouter par adresse IP

**Ajouter par adresse IP** interroge une seule adresse. Le plugin essaie d'abord
la v1 ; s'il n'obtient rien en HTTP mais qu'un service HTTPS répond « non
autorisé », il sait qu'il a affaire à un appareil HomeWizard en v2 seule, sans
savoir lequel. L'équipement est créé sous le nom « HomeWizard (API v2) » ; il
prend son vrai modèle et son numéro de série à l'association.

Dans les deux cas, l'appareil est réinterrogé par Jeedom au moment de la
création : le plugin ne se fie pas à ce que le navigateur lui envoie.

## La page de l'équipement

### Appareil

- **Adresse IP** : celle de l'appareil sur votre réseau.
- **Relever toutes les** : de la seconde à cinq minutes, **dix secondes par
  défaut**. Le P1 publie une nouvelle valeur chaque seconde, mais Jeedom
  n'historise une valeur que si elle change, et une puissance change presque
  toujours : à la seconde, l'historique grossit vite. Dix secondes suivent une
  consommation sans le remplir.
- **Tarif capacitaire** (P1 uniquement) : lit l'historique des pointes
  mensuelles dans le télégramme du compteur. Coché par défaut. Décochez-le hors
  de Belgique, ou si le calcul ne vous sert pas : le plugin cesse alors de
  demander le télégramme.

### API

L'étiquette indique l'API utilisée : **v1** ou **v2 (associé)**.

La **v1** est en HTTP, sans authentification, et suffit pour tout relever. La
**v2** est en HTTPS avec un jeton. Elle ajoute le réglage de la LED sur le P1 et
les compteurs kWh, le redémarrage à distance et la stratégie de charge des
batteries ; elle est obligatoire pour la batterie HomeWizard.

**Associer en v2** :

1. Enregistrez l'équipement avec son adresse IP.
2. Cliquez sur **Associer en v2**. Une fenêtre s'ouvre et le plugin sollicite
   l'appareil toutes les deux secondes, pendant une minute et demie.
3. Pendant ce temps, appuyez sur le bouton de l'appareil — sur le P1, le bouton
   sur le dessus ; sur un compteur kWh, le bouton Wi-Fi, une à trois secondes.
4. L'appareil délivre alors un jeton, que le plugin enregistre. La page se
   recharge en v2.

L'appareil refuse de créer un jeton tant qu'on n'a pas appuyé sur son bouton :
c'est ce qui garantit que la demande vient de quelqu'un qui y a physiquement
accès. Une nouvelle association remplace la précédente.

Le certificat HTTPS de l'appareil est signé par l'autorité de HomeWizard, que
rien sur la machine Jeedom ne connaît ; sa vérification est donc désactivée,
comme le fait l'application sur le réseau local. La protection, c'est le jeton.

**Revenir en v1** supprime le jeton de l'appareil puis de Jeedom. Les commandes
propres à la v2 (LED, redémarrage, stratégie de charge) restent, mais sans effet.

**Passer de v1 à v2, ou l'inverse, ne change aucune commande.** Les deux API ne
nomment pas les champs de la même façon ; le plugin ramène tout aux noms de la
v2 dès la réception, et c'est sous ces noms que les commandes sont identifiées.
L'historique et les scénarios sont conservés.

### Identité

Modèle, numéro de série, micrologiciel et version de l'API, relus toutes les
heures. Le bouton **Relever maintenant** force un relevé complet, dans la limite
d'un toutes les trois secondes.

## Démon et cron

Le cron de Jeedom ne sait pas descendre sous la minute. Pour un P1 qui publie
chaque seconde, c'est trop lent : d'où un démon.

- **Démon actif** : il relève chaque appareil à l'intervalle choisi. Il ne
  connaît ni les champs, ni les commandes : il reçoit du plugin une liste d'URL
  et une période pour chacune, les lit et renvoie les réponses brutes à Jeedom,
  qui les décode. Le relevé par cron passe exactement par le même décodage : les
  commandes et les valeurs sont les mêmes dans les deux cas. La clé API du
  plugin lui est transmise par l'entrée standard, jamais en argument, puisque
  la liste des processus est lisible par tout utilisateur de la machine.
- **Démon arrêté** : le cron du plugin prend le relais, **une fois par minute
  au mieux**, quel que soit l'intervalle choisi. Il s'accorde quarante secondes
  par passage ; au-delà, les appareils restants attendent la minute suivante.

Le démon relit sa configuration à chaque enregistrement d'équipement, et de
toute façon toutes les cinq minutes. Il se relance tout seul à l'enregistrement
d'un équipement si la gestion automatique est active.

Toutes les ressources ne sont pas relues au même rythme : seules les mesures
suivent l'intervalle choisi.

| Ressource | Rythme |
|---|---|
| Mesures (et état de la prise en v1) | l'intervalle de l'équipement |
| Système (Wi-Fi, cloud, LED, temps de fonctionnement) | 1 minute (5 minutes pour un compteur kWh en v1) |
| Batteries pilotées (P1 et compteur kWh en v2) | 1 minute |
| Télégramme du P1 (tarif capacitaire) | 15 minutes |
| Identité de l'appareil | 1 heure |

Un appareil injoignable est retenté de plus en plus rarement, jusqu'à cinq
minutes d'écart, pour ne pas retarder les autres. Le journal n'en garde qu'une
ligne en `warning`, au troisième échec consécutif, puis une ligne en `info` au
retour.

La **configuration du plugin** propose un seul réglage : le délai d'attente des
requêtes, trois secondes par défaut (de 1 à 15). Sur le réseau local, un
HomeWizard répond en quelques dizaines de millisecondes, ou pas du tout.

## Les commandes

### Des commandes créées au premier relevé

Les commandes ne sont pas devinées d'après le modèle : chacune est créée au
premier relevé qui contient le champ correspondant, comme le fait Home
Assistant. Un équipement tout juste créé n'a donc que **Rafraîchir** et ses
actions ; les commandes info arrivent au premier relevé, quelques secondes plus
tard.

Une commande existante n'est jamais récrite : son nom, son affichage et son
historisation vous appartiennent. Un champ nul ou absent d'une réponse ne
produit rien ; la commande garde sa dernière valeur plutôt que de tomber à zéro.

Toutes les commandes info sont listées ci-dessous. Celles marquées *masquée*
existent et sont utilisables en scénario, mais ne s'affichent pas par défaut.

### Puissance

| Commande | Unité | Ce que c'est |
|---|---|---|
| Puissance | W | Signée sur le P1 : positive au soutirage, négative à l'injection |
| Puissance soutirée | W | La part positive, *masquée* |
| Puissance injectée | W | La part négative, en positif, *masquée* |
| Puissance phase 1…3 | W | Une par phase |

Les trois premières disent la même chose sous trois formes : la signée se prête
aux scénarios (`< 0` veut dire « on injecte »), les deux autres aux widgets
énergie de Jeedom, qui attendent des valeurs positives distinctes.

### Énergie

| Commande | Unité | Ce que c'est |
|---|---|---|
| Importation d'énergie | kWh | Index total de soutirage |
| Exportation d'énergie | kWh | Index total d'injection |
| Énergie importée tarif 1…4 | kWh | Index de soutirage par tarif |
| Énergie exportée tarif 1…4 | kWh | Index d'injection par tarif |
| Tarif | | Tarif en cours, tel que le compteur l'annonce |
| Import du jour / Export du jour | kWh | Calculés, voir ci-dessous |
| Import de la veille / Export de la veille | kWh | Calculés, *masquées* |

Une prise ou un compteur kWh répète son index total sous le nom « tarif 1 ».
Sans tarif 2, ce doublon n'apprend rien : le plugin ne crée alors pas de
commande par tarif.

**Du jour et de la veille.** L'appareil ne les donne pas ; le plugin les tire
des index. Au premier relevé de la journée, il retient l'index de départ ;
l'énergie du jour est la différence avec l'index courant. La veille n'est
calculée que si le relevé de la veille a bien eu lieu : après une coupure de
plusieurs jours, la différence couvrirait toute la période et induirait en
erreur. L'énergie consommée entre le dernier relevé de la veille et minuit est
comptée dans la nouvelle journée — quelques secondes à l'intervalle par défaut.

### Tarif capacitaire (P1)

Depuis 2023, en Flandre, une part de la facture de réseau dépend de la **pointe
quart-horaire** : la plus forte puissance moyenne soutirée sur un quart d'heure,
mois par mois. L'assiette est la **moyenne des pointes mensuelles des douze
derniers mois**, chaque mois comptant pour **2,5 kW au moins**. Un mois calme à
1,2 kW compte donc pour 2,5 kW : c'est ce plancher qui explique qu'une moyenne
calculée à la main, sur les pointes brutes, sorte plus basse que celle de la
facture.

| Commande | Unité | Ce que c'est |
|---|---|---|
| Demande moyenne | W | Moyenne du quart d'heure en cours, celle qui fera la pointe |
| Demande de pointe du mois en cours | W | La plus forte depuis le début du mois |
| Heure de la pointe du mois | | Son horodatage, *masquée* |
| Pointe du mois précédent | W | *masquée* |
| Pointe maximale sur 12 mois | W | *masquée* |
| Moyenne des pointes 12 mois (tarif capacitaire) | W | Plancher de 2,5 kW appliqué à chaque mois |
| Historique des pointes mensuelles | | Texte : `2026-09 : 2,728 kW (2026-09-19 08:30) \| …`, *masquée* |

L'API ne donne que la pointe du mois en cours. Les mois précédents viennent du
**télégramme** du compteur, que le plugin lit tous les quarts d'heure si l'option
**Tarif capacitaire** est cochée : le compteur belge (protocole e-MUCS) y garde
les pointes des treize derniers mois. La moyenne porte sur les douze plus
récents, mois en cours compris. Quand un compteur a été remis à l'heure en cours
de mois, il produit deux entrées pour le même mois ; le plugin garde la plus
forte.

Hors de Flandre, ces commandes restent une information utile, mais le plancher
de 2,5 kW est celui de la règle flamande.

### Qualité du réseau

| Commande | Unité | |
|---|---|---|
| Tension, Tension phase 1…3 | V | *masquées* |
| Courant, Courant phase 1…3 | A | *masquées* |
| Fréquence | Hz | *masquée* |
| Puissance apparente (et par phase) | VA | *masquées* |
| Puissance réactive (et par phase) | var | *masquées* |
| Facteur de puissance (et par phase) | % | En pourcentage, comme dans Home Assistant, *masquées* |
| Courant apparent / réactif | A | *masquées* |
| Coupures de courant détectées | | Depuis la mise en service du compteur, *masquée* |
| Coupures de courant prolongées détectées | | *masquée* |
| Creux de tension détectés phase 1…3 | | *masquées* |
| Surtensions détectées phase 1…3 | | *masquées* |

### Gaz, eau et chaleur raccordés au P1

Le P1 relaie les compteurs raccordés au compteur électrique. Chacun donne une
commande à son nom — **Compteur de gaz**, **Compteur d'eau**, **Compteur de
chaleur**, **Compteur d'eau chaude**, **Compteur de chaleur (entrée)** — avec
l'unité annoncée par l'appareil (m³, GJ…), et une commande **(relevé)**,
*masquée*, qui porte l'heure de sa dernière valeur. Un compteur de gaz n'envoie
en général qu'une valeur toutes les cinq minutes, voire toutes les heures.

Deux compteurs du même type sont numérotés dans l'ordre où l'appareil les
présente : **Compteur de gaz**, **Compteur de gaz 2**. La numérotation reste
stable tant qu'on n'en débranche pas un.

Les anciens champs de la v1, **Gaz (ancien champ)** et **Horodatage du gaz
(ancien champ)**, sont repris s'ils sont présents ; préférez les commandes
ci-dessus.

### Compteur d'eau

| Commande | Unité | |
|---|---|---|
| Consommation d'eau | L/min | Débit instantané |
| Consommation d'eau totale | m³ | Index |
| Eau du jour | L | Calculée, en litres : à l'échelle d'une journée, les m³ ne se lisent pas |
| Eau de la veille | L | *masquée* |

### Prise

**État de la prise** (0/1), **Verrouillage de l'interrupteur** (*masquée*),
**Luminosité du voyant d'état** en % (*masquée* ; l'anneau va de 0 à 255 en v1,
ramené en pourcentage).

### Batterie

Sur la batterie elle-même (v2) : **État de charge** (%), **Cycles de la
batterie**, puissance, tension, courant et index d'énergie.

Sur le P1 ou le compteur kWh associé en v2, qui pilote le groupe de batteries :
**Stratégie de charge des batteries**, **Nombre de batteries**, **Puissance du
groupe de batteries**, **Puissance cible du groupe de batteries**, **Puissance
de charge maximale**, **Puissance de décharge maximale**, toutes *masquées*.

### Diagnostic

**Version DSMR**, **Modèle de compteur intelligent**, **Identifiant du compteur
intelligent** (le numéro imprimé sur le compteur, décodé de l'hexadécimal),
**Identifiant du compteur de gaz**, **Réseau Wi-Fi**, **Puissance du Wi-Fi** (%),
**RSSI Wi-Fi** (dBm), **Temps de fonctionnement** (h), **Connexion au cloud**
(0/1) et **En ligne** (1 quand l'appareil a répondu au dernier relevé). Toutes
*masquées*.

## Les actions

| Action | Appareils | API |
|---|---|---|
| Rafraîchir | tous | v1, v2 |
| Allumer / Éteindre | prise | v1 |
| Verrouiller / Déverrouiller | prise ; verrouillée, elle ne se coupe plus, ni par le bouton ni à distance | v1 |
| Identifier | tous sauf les compteurs kWh ; l'appareil fait clignoter sa LED | v1, v2 |
| Activer le cloud / Désactiver le cloud | tous sauf la batterie | v1, v2 |
| Régler la luminosité du voyant | prise en v1 ; tout appareil en v2 | v1, v2 |
| Redémarrer | tous sauf la batterie | v2 |
| Choisir la stratégie de charge | P1 et compteur kWh | v2 |

**Désactiver le cloud** coupe l'appareil de l'application HomeWizard Energy et
de ses services en ligne ; le relevé local continue. C'est réversible par
l'action inverse.

**Stratégie de charge** — les valeurs proposées :

| Valeur | Libellé |
|---|---|
| `zero` | Zéro net : la batterie charge sur le surplus et décharge pour couvrir la consommation |
| `zero_charge_only` | Zéro net, charge uniquement |
| `zero_discharge_only` | Zéro net, décharge uniquement |
| `to_full` | Charge complète unique |
| `standby` | En veille |
| `predictive` | Charge intelligente |

Les actions n'existent que pour les appareils qui les savent faire : elles sont
choisies d'après le modèle et l'API utilisée. Associer un appareil en v2 ajoute
les actions de la v2 ; revenir en v1 ne les retire pas, elles restent sans effet.

## Bon à savoir

**Tarif 1 et tarif 2 ne veulent pas dire la même chose partout.** Le plugin
reprend le numéro de tarif que le compteur publie, sans le réinterpréter. Leur
signification (jour ou nuit, normal ou réduit) dépend du pays, du gestionnaire
de réseau et du contrat : un compteur Fluvius observé pendant l'écriture de ce
plugin annonçait par exemple le tarif 2 un vendredi en fin de matinée. Avant de
renommer les commandes, comparez la commande **Tarif** à votre contrat à une
heure connue, de jour en semaine puis la nuit.

**Les commandes portent les noms de la v2 pour identifiant logique**
(`power_w`, `energy_import_t1_kwh`…). Changer d'API ne change donc aucune
commande, et ne perd ni historique ni scénario. L'identifiant logique s'affiche
au survol de chaque ligne de l'onglet *Commandes*.

**Les commandes arrivent au premier relevé**, pas à la création de
l'équipement. Si un compteur de gaz est raccordé plus tard, sa commande apparaît
au relevé suivant, sans rien toucher.

**L'équipement est identifié par le numéro de série** de l'appareil. Une adresse
IP qui change n'en crée pas un second : relancez la recherche.

## Dépannage

**« L'API locale v1 est désactivée »** (réponse HTTP 403, « API not enabled ») :
activez-la dans l'application HomeWizard Energy, **Réglages → Compteurs →
l'appareil → API locale**, ou associez l'appareil en v2.

**« Jeton v2 refusé »** (réponse HTTP 401, `user:unauthorized`) : le jeton n'est
plus valable, par exemple après une remise à zéro de l'appareil ou une
association faite ailleurs sous le même nom. Refaites l'association depuis la
page de l'équipement.

**« Le P1 n'a pas encore reçu de télégramme du compteur »** : le P1 est bien
joignable mais n'a rien lu sur le port P1. Vérifiez qu'il est bien enfiché, et
que le port P1 est ouvert chez votre gestionnaire de réseau (en Flandre, il
s'active sur le portail de Fluvius).

**La recherche ne trouve rien.** Elle passe par l'API v1 et ne couvre que le
sous-réseau indiqué. Si Jeedom tourne dans un conteneur ou sur un autre VLAN,
précisez le sous-réseau des appareils, ou ajoutez-les par leur adresse IP.
Depuis la machine Jeedom, un appareil v1 doit répondre à :

```bash
curl -s --max-time 3 http://192.168.0.150/api
```

**L'enregistrement de l'équipement ne fait rien** : la page se recharge et la
saisie disparaît. Regardez d'abord `/var/www/html/log/http.error` : une erreur
PHP y figure, alors que le journal du plugin reste muet.

**Une valeur paraît fausse.** L'onglet **Diagnostic** de l'équipement indique la
date du dernier relevé réussi, l'API utilisée, l'état du démon, et montre **les
dernières réponses brutes de l'appareil**, JSON et télégramme, avant toute
conversion. C'est la pièce à joindre à un signalement : elle dit tout de suite
si la valeur vient de l'appareil ou du plugin.

Les journaux sont dans **Analyse → Logs** : `homewizardbe` pour le plugin,
`homewizardbed` pour le démon. Passez-les en `debug` pour voir chaque échec de
relevé.

## Confidentialité

Tout se passe sur votre réseau local. Le plugin ne contacte ni HomeWizard, ni
aucun autre service en ligne, et ne demande aucun compte. Le jeton v2 est gardé
dans la configuration de l'équipement, sur votre Jeedom.

Ce plugin n'est pas affilié à HomeWizard, et n'est ni parrainé ni approuvé par
elle. HomeWizard et HomeWizard Energy sont des marques de leur propriétaire.
