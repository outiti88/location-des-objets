<?php

namespace App\Controller;

use App\Entity\Ad;
use App\Form\AdType;
use App\Entity\Image;
use App\Entity\Filter;
use App\Entity\Premium;
use App\Form\FilterType;
use App\Repository\AdRepository;
use App\Repository\CityRepository;
use App\Repository\CategoryRepository;
use App\Repository\PremiumRepository;
use Doctrine\ORM\EntityManagerInterface;
use App\Repository\SubCategoryRepository;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Knp\Component\Pager\Pagination\PaginationInterface;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

class AdController extends AbstractController
{
    /**
     * @Route("/ads", name="ads_index")
     * @return Response
     */
    public function index(PaginatorInterface $paginator, Request $request, AdRepository $repo, PremiumRepository $premiumRepository, EntityManagerInterface $manager): Response
    {
        $filter = new Filter();
        $form = $this->createForm(FilterType::class, $filter);
        $form->handleRequest($request);
        $premiums = $premiumRepository->findFilter($filter);
        $ads = array();
        foreach ($premiums as $premium) {
            $ad = $premium->getAd();
            if ($premium->getValue()) {
                $ad->setPremiumValue(true);
            }
            $ads[] = $ad;
        }
        foreach ($ads as $ad) {
            $dates = $ad->getNotAvailableDays();
            $dateStart = \DateTime::createFromFormat('d/m/Y', $filter->getStartDate());
            $dateEnd = \DateTime::createFromFormat('d/m/Y', $filter->getEndDate());
            foreach ($dates as $date) {
                if ($date >= $dateStart && $date <= $dateEnd) {
                    $key = array_search($ad, $ads, true);
                    unset($ads[$key]);
                    break;
                }
            }
        }

        $ads = $paginator->paginate(
            $ads,
            $request->query->getInt('page', 1),
            6 //nombre d'annoces
        );


        return $this->render('ad/index.html.twig', [
            'ads' => $ads,
            'form' => $form->createView()
        ]);
    }

    /**
     * Permet de communiquer à ajax les sous-catégories d'une catégorie aprés avoir reçu son titre
     * 
     * @Route("/ads/subCategory", name="ads_subCategory")
     *
     * @param Request $resquest
     * @param SubCategoryRepository $repo
     * @return Response
     */
    public function getSubCategoriesByCategory(Request $request, SubCategoryRepository $subRepo, CategoryRepository $catRepo): Response
    {
        $postData = json_decode($request->getContent());
        $categoryTitle = $postData->category;
        $subCategoryArray = [];
        $category = $catRepo->findOneByTitle($categoryTitle);
        $subCategories = $category->getSubCategories();
        foreach ($subCategories as $subCategory) {
            $subCategoryArray[] = [
                'id' => $subCategory->getId(),
                'title' => $subCategory->getTitle()
            ];
        }
        return $this->json($subCategoryArray, 200);
    }

    /**
     * permet de créer une annonce
     * 
     * @Route("/ads/new", name="ads_create")
     * @IsGranted("ROLE_USER")
     *
     * @return Response
     */
    function create(Request $request, EntityManagerInterface $manager, CityRepository $cityRepository)
    {
        $ad = new Ad;

        $form = $this->createForm(AdType::class, $ad);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $startDate = new \DateTime();
            $premium = new Premium;
            if ($ad->getPremiumValue()) {
                $premium->setValue($ad->getPremiumValue())
                    ->setStartDate($startDate)
                    ->setEndDate((clone $startDate)->modify('+' . $ad->getPremiumDuration() . ' days'))
                    ->setAd($ad);
            } else {
                $premium->setValue($ad->getPremiumValue())
                    ->setAd($ad);
            }
            foreach ($ad->getImages() as $image) {
                $image->setAd($ad);
                $manager->persist($image);
            }

            $ad->setAuthor($this->getUser());

            $manager->persist($premium);
            $city = $cityRepository->findOneByName("Tout Le maroc");
            $ad->addCity($city);
            $manager->persist($ad);
            $manager->flush();

            $this->addFlash(
                'success',
                "L'annonce <strong>{$ad->getTitle()}</strong> a bien été enregistrée"
            );

            return $this->redirectToRoute('ads_show', [
                'id' => $ad->getId(),
                'slug' => $ad->getSlug()
            ]);
        }

        return $this->render('ad/new.html.twig', [
            'form' => $form->createView()
        ]);
    }

    /**
     * permet d'editer une annonce
     * 
     * @Route("/ads/{id}/{slug}/edit", name="ads_edit")
     * @Security("is_granted('ROLE_USER') and user === ad.getAuthor()", message="Cette annonce ne vous appartient pas, vous ne pouvez pas la modifier")
     *
     * @return Response
     */
    function edit(Ad $ad, Request $request, EntityManagerInterface $manager)
    {
        $form = $this->createForm(AdType::class, $ad);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {

            foreach ($ad->getImages() as $image) {
                $image->setAd($ad);
                $manager->persist($image);
            }

            $manager->persist($ad);
            $manager->flush();

            $this->addFlash(
                'success',
                "L'annonce <strong>{$ad->getTitle()}</strong> a bien été modifiée"
            );

            return $this->redirectToRoute('ads_show', [
                'slug' => $ad->getSlug()
            ]);
        }

        return $this->render('ad/edit.html.twig', [
            'form' => $form->createView(),
            'ad' => $ad
        ]);
    }


    /**
     * permet d'afficher une seule annonce
     *
     * @Route("/ads/{id}/{slug}", name="ads_show")
     * 
     * @return Response
     */
    function show(Ad $ad)
    {
        return $this->render('ad/show.html.twig', [
            "ad" => $ad
        ]);
    }

    /**
     * Permet de supprimer une annonce
     * 
     * @Route("/ads/{id}/{slug}/delete", name="ads_delete")
     * @Security("is_granted('ROLE_USER') and user === ad.getAuthor()", message="Cette annonce ne vous appartient pas, vous ne pouvez pas la supprimer")
     *
     * @return Response
     */
    function delete(Ad $ad, EntityManagerInterface $manager)
    {
        $ad->setBlackListed(true);
        $manager->persist($ad);
        $manager->flush();

        $this->addFlash(
            'success',
            "L'annonce <strong>{$ad->getTitle()}</strong> a bien été supprimée"
        );

        return $this->redirectToRoute("ads_index");
    }
}
